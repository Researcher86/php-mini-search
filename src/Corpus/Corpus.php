<?php

declare(strict_types=1);

namespace PhpMiniSearch\Corpus;

use PhpMiniSearch\Analysis\Analyzer;
use PhpMiniSearch\Index\InvertedIndex;
use PhpMiniSearch\Storage\Log;
use PhpMiniSearch\Storage\LogFormat;
use PhpMiniSearch\Storage\LogScan;

/**
 * The searchable corpus: the one module that owns the log and the index
 * together, and so the only place their order can go wrong.
 *
 * Every write follows the same order: check the source version, analyse,
 * append to the log, fsync, apply to the index, return. A search can only
 * see what is already durable, and a crash between the append and the apply
 * loses nothing, because replay applies the record again.
 *
 * Replay and live writes go through the same apply() code, record by record,
 * so the state after a restart is the state before it by construction.
 *
 * The platform owns the data (docs/DECISIONS.md); what this class protects
 * is the order in which copies of it arrive:
 *
 *  - every write carries the row's source version, and one not newer than
 *    what is held — or than a tombstone — is stale and ignored;
 *  - a delete leaves a tombstone forever, so a late PUT cannot resurrect the
 *    document, even after a reindex;
 *  - while a reindex generation is open, every applied write is marked with
 *    it, and a stale PUT that names it marks the document it confirms;
 *    completing the generation removes every live document left unmarked.
 */
final class Corpus
{
    public const int MAX_QUERY_TERMS = 32;

    /** @var array<string, array{int, ?int}> id → [source version, generation] of every live document */
    private array $documents = [];

    /** @var array<string, int> id → source version of the delete */
    private array $tombstones = [];

    /** @var array<string, int> id → bytes of the record that holds its current state, for the garbage ratio */
    private array $liveBytes = [];

    private int $nextGeneration = 1;

    private ?int $openGeneration = null;

    private ?int $lastCompletedGeneration = null;

    private bool $logWasDamaged = false;

    private Log $log;

    private function __construct(
        private readonly InvertedIndex $index,
        private readonly Analyzer $analyzer,
    ) {
    }

    /**
     * Replays the log at $path into a fresh index. A damaged log is moved
     * aside by Log::open(); whatever was replayed before the damage is
     * dropped, and the corpus starts empty in `reindex_required`.
     */
    public static function open(string $path, ?Analyzer $analyzer = null): self
    {
        $corpus = new self(new InvertedIndex(), $analyzer ?? new Analyzer());
        $corpus->log = Log::open($path, $corpus->apply(...));

        if ($corpus->log->recovery()->status === LogScan::DAMAGED) {
            $fresh = new self(new InvertedIndex(), $corpus->analyzer);
            $fresh->log = $corpus->log;
            $fresh->logWasDamaged = true;

            return $fresh;
        }

        return $corpus;
    }

    public function put(string $id, int $sourceVersion, string $title, string $body, ?int $generation = null): WriteResult
    {
        if ($this->isStale($id, $sourceVersion)) {
            $confirmsOpenGeneration = $generation !== null
                && $generation === $this->openGeneration
                && isset($this->documents[$id])
                && $this->documents[$id][1] !== $generation;

            if ($confirmsOpenGeneration) {
                $this->write(['op' => 'mark', 'id' => $id, 'generation' => $generation]);
            }

            return $this->staleResult($id);
        }

        $terms = ['title' => $this->analyzer->terms($title), 'body' => $this->analyzer->terms($body)];

        // An applied write during a reindex belongs to it, whoever sent it.
        $this->write([
            'op' => 'put',
            'id' => $id,
            'source_version' => $sourceVersion,
            'generation' => $this->openGeneration,
            'title' => $title,
            'body' => $body,
        ], $terms);

        return new WriteResult($id, true, $sourceVersion, $this->openGeneration);
    }

    public function delete(string $id, int $sourceVersion): WriteResult
    {
        if ($this->isStale($id, $sourceVersion)) {
            return $this->staleResult($id);
        }

        $this->write(['op' => 'delete', 'id' => $id, 'source_version' => $sourceVersion]);

        return new WriteResult($id, true, $sourceVersion, null);
    }

    /**
     * @return array{generation: int, cancelled_generation: ?int}
     */
    public function beginReindex(): array
    {
        $cancelled = $this->openGeneration;
        $generation = $this->nextGeneration;
        $this->write(['op' => 'reindex-begin', 'generation' => $generation]);

        return ['generation' => $generation, 'cancelled_generation' => $cancelled];
    }

    /**
     * @return int how many documents the generation did not mark, and were removed
     */
    public function completeReindex(int $generation): int
    {
        if ($generation !== $this->openGeneration) {
            throw new GenerationNotOpen("Generation {$generation} is not open.");
        }

        $removed = count(array_filter($this->documents, fn (array $document) => $document[1] !== $generation));
        $this->write(['op' => 'reindex-complete', 'generation' => $generation]);

        return $removed;
    }

    public function search(string $query, int $limit, int $offset = 0): Page
    {
        $reason = $this->reindexRequired();
        if ($reason !== null) {
            throw new ReindexRequired($reason);
        }

        $terms = array_values(array_unique($this->analyzer->terms($query)));
        if ($terms === []) {
            throw new QueryRejected(QueryRejected::EMPTY_QUERY, 'The query has no terms after analysis.');
        }
        if (count($terms) > self::MAX_QUERY_TERMS) {
            throw new QueryRejected(QueryRejected::TOO_MANY_TERMS, 'The query has more than ' . self::MAX_QUERY_TERMS . ' terms.');
        }

        $result = $this->index->search($terms, $limit, $offset);
        $hits = array_map(
            fn ($hit) => new Hit($hit->id, $hit->score, $this->documents[$hit->id][0]),
            $result->hits,
        );

        return new Page($hits, $result->total);
    }

    /**
     * Why search is refused, or null when it is not: the corpus has never
     * completed a reindex, or its log was lost.
     *
     * @return ReindexRequired::FIRST_START|ReindexRequired::LOG_CORRUPTED|null
     */
    public function reindexRequired(): ?string
    {
        if ($this->lastCompletedGeneration !== null) {
            return null;
        }

        return $this->logWasDamaged ? ReindexRequired::LOG_CORRUPTED : ReindexRequired::FIRST_START;
    }

    /**
     * The diagnostic view behind GET /documents/{id}; null for an id never seen.
     *
     * @return array<string, mixed>|null
     */
    public function describe(string $id): ?array
    {
        if (isset($this->documents[$id])) {
            [$version, $generation] = $this->documents[$id];

            return [
                'id' => $id,
                'state' => 'live',
                'source_version' => $version,
                'generation' => $generation,
                'fields' => $this->index->describe($id),
            ];
        }

        if (isset($this->tombstones[$id])) {
            return ['id' => $id, 'state' => 'deleted', 'source_version' => $this->tombstones[$id]];
        }

        return null;
    }

    /**
     * @return array{documents: int, tombstones: int, terms: int, open_generation: ?int, last_completed_generation: ?int, log: array{bytes: int, garbage_ratio: float}}
     */
    public function stats(): array
    {
        clearstatcache(true, $this->log->path());
        $bytes = (int) filesize($this->log->path());
        $live = strlen(LogFormat::header()) + array_sum($this->liveBytes);

        return [
            'documents' => count($this->documents),
            'tombstones' => count($this->tombstones),
            'terms' => $this->index->termCount(),
            'open_generation' => $this->openGeneration,
            'last_completed_generation' => $this->lastCompletedGeneration,
            'log' => ['bytes' => $bytes, 'garbage_ratio' => $bytes > 0 ? round(max(0, 1 - $live / $bytes), 4) : 0.0],
        ];
    }

    /**
     * Everything that must survive a restart or a compaction, in a canonical
     * order. Tests compare it before and after; it is not part of the API.
     *
     * @return array<string, mixed>
     */
    public function state(): array
    {
        $documents = $this->documents;
        $tombstones = $this->tombstones;
        ksort($documents, SORT_STRING);
        ksort($tombstones, SORT_STRING);

        $fields = [];
        foreach (array_keys($documents) as $id) {
            $fields[$id] = $this->index->describe((string) $id);
        }

        return [
            'documents' => $documents,
            'tombstones' => $tombstones,
            'fields' => $fields,
            'next_generation' => $this->nextGeneration,
            'open_generation' => $this->openGeneration,
            'last_completed_generation' => $this->lastCompletedGeneration,
            'reindex_required' => $this->reindexRequired(),
        ];
    }

    /**
     * The records a compacted log consists of: one checkpoint with the state
     * that only history knows, then the current record of every live document
     * and every tombstone. $lastPuts are the put records of the live
     * documents, read back from the log, since the corpus keeps terms, not
     * text.
     *
     * @param array<string, array<string, mixed>> $lastPuts id → its last put record
     * @return iterable<array<string, mixed>>
     */
    public function compactedRecords(array $lastPuts): iterable
    {
        yield [
            'op' => 'checkpoint',
            'next_generation' => $this->nextGeneration,
            'open_generation' => $this->openGeneration,
            'last_completed_generation' => $this->lastCompletedGeneration,
        ];

        foreach ($this->documents as $id => [$version, $generation]) {
            $record = $lastPuts[$id];
            $record['source_version'] = $version;
            $record['generation'] = $generation;

            yield $record;
        }

        foreach ($this->tombstones as $id => $version) {
            yield ['op' => 'delete', 'id' => (string) $id, 'source_version' => $version];
        }
    }

    public function close(): void
    {
        $this->log->close();
    }

    private function isStale(string $id, int $sourceVersion): bool
    {
        return $sourceVersion <= ($this->documents[$id][0] ?? 0)
            || $sourceVersion <= ($this->tombstones[$id] ?? 0);
    }

    private function staleResult(string $id): WriteResult
    {
        if (isset($this->documents[$id])) {
            return new WriteResult($id, false, $this->documents[$id][0], $this->documents[$id][1]);
        }

        return new WriteResult($id, false, $this->tombstones[$id] ?? 0, null);
    }

    /**
     * Append, sync, then apply. The order is the whole point of this class.
     *
     * @param array<string, mixed> $record
     * @param array<string, list<string>>|null $terms already analysed, for a put
     */
    private function write(array $record, ?array $terms = null): void
    {
        $this->log->append($record);
        $this->apply($record, $terms);
    }

    /**
     * Applies one record to the in-memory state. Used for live writes and
     * for replay alike.
     *
     * @param array<string, mixed> $record
     * @param array<string, list<string>>|null $terms
     */
    private function apply(array $record, ?array $terms = null): void
    {
        $id = isset($record['id']) && is_scalar($record['id']) ? (string) $record['id'] : '';
        $version = isset($record['source_version']) && is_int($record['source_version']) ? $record['source_version'] : 0;
        $generation = isset($record['generation']) && is_int($record['generation']) ? $record['generation'] : null;
        $bytes = strlen(LogFormat::frame($record));

        switch ($record['op'] ?? null) {
            case 'put':
                $title = is_string($record['title'] ?? null) ? $record['title'] : '';
                $body = is_string($record['body'] ?? null) ? $record['body'] : '';
                $this->index->put($id, $terms ?? ['title' => $this->analyzer->terms($title), 'body' => $this->analyzer->terms($body)]);
                $this->documents[$id] = [$version, $generation];
                $this->liveBytes[$id] = $bytes;
                unset($this->tombstones[$id]);
                break;

            case 'delete':
                $this->index->delete($id);
                unset($this->documents[$id]);
                $this->tombstones[$id] = $version;
                $this->liveBytes[$id] = $bytes;
                break;

            case 'mark':
                if (isset($this->documents[$id])) {
                    $this->documents[$id][1] = $generation;
                }
                break;

            case 'reindex-begin':
                $this->openGeneration = $generation;
                $this->nextGeneration = ($generation ?? 0) + 1;
                break;

            case 'reindex-complete':
                foreach ($this->documents as $documentId => [$documentVersion, $documentGeneration]) {
                    if ($documentGeneration !== $generation) {
                        $documentId = (string) $documentId;
                        $this->index->delete($documentId);
                        unset($this->documents[$documentId]);
                        $this->tombstones[$documentId] = $documentVersion;
                        $this->liveBytes[$documentId] = strlen(LogFormat::frame(
                            ['op' => 'delete', 'id' => $documentId, 'source_version' => $documentVersion],
                        ));
                    }
                }
                $this->openGeneration = null;
                $this->lastCompletedGeneration = $generation;
                break;

            case 'checkpoint':
                $next = $record['next_generation'] ?? 1;
                $open = $record['open_generation'] ?? null;
                $last = $record['last_completed_generation'] ?? null;
                $this->nextGeneration = is_int($next) ? $next : 1;
                $this->openGeneration = is_int($open) ? $open : null;
                $this->lastCompletedGeneration = is_int($last) ? $last : null;
                break;
        }
    }
}
