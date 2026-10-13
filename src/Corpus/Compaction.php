<?php

declare(strict_types=1);

namespace PhpMiniSearch\Corpus;

use PhpMiniSearch\Storage\Log;
use PhpMiniSearch\Storage\LogScanner;

/**
 * Offline compaction: `bin/mini-search compact`, with the service stopped.
 *
 * Every update appends a full copy of a document, so the log keeps the whole
 * history. Compaction keeps only the present:
 *
 *  1. open the corpus, which takes the lock, repairs a torn tail and
 *     replays everything — the state compaction must preserve;
 *  2. read the log once more for the text of each live document's last put,
 *     since the corpus keeps terms, not text;
 *  3. write a checkpoint plus one record per live document and per
 *     tombstone to a temporary file, sync it, rename it over the log, sync
 *     the directory (Log::rewrite()).
 *
 * Replaying the result gives the same corpus as replaying the original; that
 * property is the test.
 */
final class Compaction
{
    /**
     * @return array{bytes_before: int, bytes_after: int}
     */
    public static function run(string $path): array
    {
        $corpus = Corpus::open($path);

        try {
            clearstatcache(true, $path);
            $before = (int) filesize($path);

            /** @var array<string, array<string, mixed>> $lastPuts */
            $lastPuts = [];
            LogScanner::scan($path, function (array $record) use (&$lastPuts): void {
                if (($record['op'] ?? null) === 'put' && is_string($record['id'] ?? null)) {
                    $lastPuts[$record['id']] = $record;
                }
            });

            Log::rewrite($path, $corpus->compactedRecords($lastPuts));
        } finally {
            $corpus->close();
        }

        clearstatcache(true, $path);

        return ['bytes_before' => $before, 'bytes_after' => (int) filesize($path)];
    }
}
