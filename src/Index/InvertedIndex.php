<?php

declare(strict_types=1);

namespace PhpMiniSearch\Index;

use InvalidArgumentException;

/**
 * The inverted index: for every term, which documents contain it and how
 * often. It lives in memory only; the log rebuilds it on every start.
 *
 * Postings are plain PHP arrays, `field → term → [docNum => tf]`. That was
 * measured, not guessed: about 319 MB for 10,000 documents, and replacing a
 * document is one unset() per term (docs/DECISIONS.md). Each document gets a
 * small internal number when it is added, and a new one when it is replaced,
 * so the posting arrays never hold an id string per entry.
 *
 * To remove a document, the index has to know which posting lists mention
 * it. A full forward index (`doc → term → tf`) would roughly double the
 * memory, so each document keeps only its distinct terms per field, joined
 * into one string with "\0" — terms are letters and digits, never NUL. The
 * term frequencies are already in the postings.
 *
 * Queries are AND: a document matches when every query term appears in at
 * least one field. Scoring is BM25 per field, weighted and summed:
 *
 *   score = Σ terms Σ fields  w_f · idf_f(t) · tf·(k1+1) / (tf + k1·(1 − b + b·len/avglen))
 *   idf_f(t) = ln(1 + (N − df_f(t) + 0.5) / (df_f(t) + 0.5))
 *
 * The idf is Lucene's variant, which never goes negative. Robertson's
 * original drops below zero for a term in more than half the documents, and
 * under AND such a term is in every match, so it would push all of them down
 * together. Terms are summed in sorted order and fields in a fixed order,
 * because floating-point addition depends on the order of its operands, and
 * the same query must always rank the same way.
 */
final class InvertedIndex
{
    /** @var array<string, array<string, array<int, int>>> field → term → docNum → term frequency */
    private array $postings = [];

    /** @var array<string, array<int, int>> field → docNum → number of terms in that field */
    private array $lengths = [];

    /** @var array<string, int> field → sum of the lengths of that field over all documents */
    private array $totalLengths = [];

    /** @var array<string, array<int, string>> field → docNum → the document's distinct terms, "\0"-joined */
    private array $termsOf = [];

    /** @var array<string, int> id → docNum */
    private array $numbers = [];

    /** @var array<int, string> docNum → id */
    private array $ids = [];

    private int $nextNumber = 0;

    /**
     * @param array<string, float> $weights field → weight; the keys are the only fields the index accepts
     */
    public function __construct(
        private readonly array $weights = ['title' => 2.0, 'body' => 1.0],
        private readonly float $k1 = 1.2,
        private readonly float $b = 0.75,
    ) {
        foreach (array_keys($weights) as $field) {
            $this->postings[$field] = [];
            $this->lengths[$field] = [];
            $this->totalLengths[$field] = 0;
            $this->termsOf[$field] = [];
        }
    }

    /**
     * Adds a document, or replaces it if the id is already indexed.
     *
     * @param array<string, list<string>> $fields field → terms in text order, duplicates included; a missing field is empty
     */
    public function put(string $id, array $fields): void
    {
        $unknown = array_diff_key($fields, $this->weights);
        if ($unknown !== []) {
            throw new InvalidArgumentException('Unknown field: ' . implode(', ', array_keys($unknown)));
        }

        $this->delete($id);

        $number = $this->nextNumber++;
        $this->numbers[$id] = $number;
        $this->ids[$number] = $id;

        foreach (array_keys($this->weights) as $field) {
            $terms = $fields[$field] ?? [];
            $frequencies = array_count_values($terms);

            foreach ($frequencies as $term => $tf) {
                $this->postings[$field][(string) $term][$number] = $tf;
            }

            $this->lengths[$field][$number] = count($terms);
            $this->totalLengths[$field] += count($terms);
            $this->termsOf[$field][$number] = implode("\0", array_map('strval', array_keys($frequencies)));
        }
    }

    public function delete(string $id): void
    {
        $number = $this->numbers[$id] ?? null;
        if ($number === null) {
            return;
        }

        foreach (array_keys($this->weights) as $field) {
            foreach ($this->distinctTerms($field, $number) as $term) {
                unset($this->postings[$field][$term][$number]);

                if ($this->postings[$field][$term] === []) {
                    unset($this->postings[$field][$term]);
                }
            }

            $this->totalLengths[$field] -= $this->lengths[$field][$number];
            unset($this->lengths[$field][$number], $this->termsOf[$field][$number]);
        }

        unset($this->numbers[$id], $this->ids[$number]);
    }

    public function has(string $id): bool
    {
        return isset($this->numbers[$id]);
    }

    public function documentCount(): int
    {
        return count($this->ids);
    }

    /**
     * Distinct terms over all fields.
     */
    public function termCount(): int
    {
        $terms = [];
        foreach ($this->postings as $byTerm) {
            $terms += $byTerm;
        }

        return count($terms);
    }

    /**
     * What the index holds for one document: per field, its length and its
     * terms with their frequencies. Null if the id is not indexed.
     *
     * @return array<string, array{length: int, terms: array<string, int>}>|null
     */
    public function describe(string $id): ?array
    {
        $number = $this->numbers[$id] ?? null;
        if ($number === null) {
            return null;
        }

        $fields = [];
        foreach (array_keys($this->weights) as $field) {
            $terms = [];
            foreach ($this->distinctTerms($field, $number) as $term) {
                $terms[$term] = $this->postings[$field][$term][$number];
            }

            $fields[$field] = ['length' => $this->lengths[$field][$number], 'terms' => $terms];
        }

        return $fields;
    }

    /**
     * @param list<string> $terms the analysed query; order and duplicates do not matter
     */
    public function search(array $terms, int $limit, int $offset = 0): SearchResult
    {
        $terms = array_values(array_unique($terms));
        sort($terms, SORT_STRING);

        if ($terms === [] || $limit < 1 || $offset < 0) {
            return new SearchResult([], 0);
        }

        $top = new TopHits($offset + $limit);
        $total = 0;

        foreach ($this->matches($terms) as $number) {
            $total++;
            $top->offer(new SearchHit($this->ids[$number], $this->score($terms, $number)));
        }

        return new SearchResult(array_slice($top->best(), $offset), $total);
    }

    /**
     * Every invariant the index promises, checked from scratch. Empty when
     * the index is consistent; tests call it after every operation.
     *
     * @return list<string>
     */
    public function invariantViolations(): array
    {
        $violations = [];

        if (count($this->numbers) !== count($this->ids)) {
            $violations[] = 'id → number and number → id differ in size';
        }
        foreach ($this->numbers as $id => $number) {
            if (($this->ids[$number] ?? null) !== (string) $id) {
                $violations[] = "id {$id} does not map back to itself";
            }
        }

        foreach (array_keys($this->weights) as $field) {
            $postingsPerDocument = [];

            foreach ($this->postings[$field] as $term => $documents) {
                if ($documents === []) {
                    $violations[] = "{$field}: term {$term} has an empty posting list";
                }
                foreach ($documents as $number => $tf) {
                    if (!isset($this->ids[$number])) {
                        $violations[] = "{$field}: term {$term} points at deleted document #{$number}";
                    }
                    if ($tf < 1) {
                        $violations[] = "{$field}: term {$term} has frequency {$tf} in #{$number}";
                    }
                    $postingsPerDocument[$number] = ($postingsPerDocument[$number] ?? 0) + 1;
                }
            }

            $total = 0;
            foreach ($this->ids as $number => $id) {
                $distinct = $this->distinctTerms($field, $number);
                $sum = 0;
                foreach ($distinct as $term) {
                    $sum += $this->postings[$field][$term][$number] ?? 0;
                }
                if ($sum !== ($this->lengths[$field][$number] ?? null)) {
                    $violations[] = "{$field}: frequencies of {$id} add up to {$sum}, not its length";
                }
                if (count($distinct) !== ($postingsPerDocument[$number] ?? 0)) {
                    $violations[] = "{$field}: {$id} has postings its term list does not name, or the reverse";
                }
                $total += $this->lengths[$field][$number] ?? 0;
            }

            if ($total !== $this->totalLengths[$field]) {
                $violations[] = "{$field}: total length {$this->totalLengths[$field]} ≠ sum of lengths {$total}";
            }
            if (count($this->lengths[$field]) !== count($this->ids) || count($this->termsOf[$field]) !== count($this->ids)) {
                $violations[] = "{$field}: per-document tables hold deleted documents";
            }
        }

        return $violations;
    }

    /**
     * The documents containing every term, in any field. Starts from the
     * rarest term, so the work is bounded by its posting lists.
     *
     * @param non-empty-list<string> $terms
     * @return iterable<int> document numbers
     */
    private function matches(array $terms): iterable
    {
        $bySize = [];
        foreach ($terms as $term) {
            $size = 0;
            foreach (array_keys($this->weights) as $field) {
                $size += count($this->postings[$field][$term] ?? []);
            }
            if ($size === 0) {
                return;
            }
            $bySize[$term] = $size;
        }
        asort($bySize);
        $ordered = array_map('strval', array_keys($bySize));
        $rarest = array_shift($ordered);

        $candidates = [];
        foreach (array_keys($this->weights) as $field) {
            $candidates += $this->postings[$field][$rarest] ?? [];
        }

        foreach (array_keys($candidates) as $number) {
            foreach ($ordered as $term) {
                if (!$this->contains($term, $number)) {
                    continue 2;
                }
            }

            yield $number;
        }
    }

    private function contains(string $term, int $number): bool
    {
        foreach (array_keys($this->weights) as $field) {
            if (isset($this->postings[$field][$term][$number])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $terms sorted
     */
    private function score(array $terms, int $number): float
    {
        $documents = count($this->ids);
        $score = 0.0;

        foreach ($terms as $term) {
            foreach ($this->weights as $field => $weight) {
                $tf = $this->postings[$field][$term][$number] ?? 0;
                if ($tf === 0) {
                    continue;
                }

                $df = count($this->postings[$field][$term] ?? []);
                $idf = log(1 + ($documents - $df + 0.5) / ($df + 0.5));
                $norm = 1 - $this->b + $this->b * $this->lengths[$field][$number] * $documents / $this->totalLengths[$field];

                $score += $weight * $idf * $tf * ($this->k1 + 1) / ($tf + $this->k1 * $norm);
            }
        }

        return $score;
    }

    /**
     * @return list<string>
     */
    private function distinctTerms(string $field, int $number): array
    {
        $joined = $this->termsOf[$field][$number] ?? '';

        return $joined === '' ? [] : explode("\0", $joined);
    }
}
