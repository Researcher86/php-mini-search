<?php

declare(strict_types=1);

namespace PhpMiniSearch\Tests\Support;

/**
 * The oracle the inverted index is checked against: the same search, done the
 * slowest and most obvious way.
 *
 * It keeps every document's terms as given and, on each query, scans every
 * document, counts term frequencies, document frequencies and average lengths
 * from scratch, applies the BM25 formula from docs/ARCHITECTURE.md directly,
 * and sorts everything. No postings, no heap, no incremental bookkeeping — so
 * none of the index's bugs can be shared by it.
 *
 * Terms and fields are summed in the same order as the index, so the two
 * produce bit-identical scores when both are right.
 */
final class NaiveSearch
{
    /** @var array<string, array<string, list<string>>> id → field → terms */
    private array $documents = [];

    /**
     * @param array<string, float> $weights
     */
    public function __construct(
        private readonly array $weights = ['title' => 2.0, 'body' => 1.0],
        private readonly float $k1 = 1.2,
        private readonly float $b = 0.75,
    ) {
    }

    /**
     * @param array<string, list<string>> $fields
     */
    public function put(string $id, array $fields): void
    {
        $this->documents[$id] = $fields;
    }

    public function delete(string $id): void
    {
        unset($this->documents[$id]);
    }

    /**
     * @param list<string> $terms
     * @return array{hits: list<array{string, float}>, total: int}
     */
    public function search(array $terms, int $limit, int $offset): array
    {
        $terms = array_values(array_unique($terms));
        sort($terms, SORT_STRING);
        if ($terms === []) {
            return ['hits' => [], 'total' => 0];
        }

        $count = count($this->documents);
        $hits = [];

        foreach ($this->documents as $id => $fields) {
            $id = (string) $id;
            foreach ($terms as $term) {
                $found = false;
                foreach (array_keys($this->weights) as $field) {
                    if (in_array($term, $fields[$field] ?? [], true)) {
                        $found = true;
                    }
                }
                if (!$found) {
                    continue 2;
                }
            }

            $score = 0.0;
            foreach ($terms as $term) {
                foreach ($this->weights as $field => $weight) {
                    $tf = count(array_keys($fields[$field] ?? [], $term, true));
                    if ($tf === 0) {
                        continue;
                    }

                    $df = 0;
                    $total = 0;
                    foreach ($this->documents as $other) {
                        $df += in_array($term, $other[$field] ?? [], true) ? 1 : 0;
                        $total += count($other[$field] ?? []);
                    }
                    $length = count($fields[$field] ?? []);

                    $idf = log(1 + ($count - $df + 0.5) / ($df + 0.5));
                    $norm = 1 - $this->b + $this->b * $length * $count / $total;
                    $score += $weight * $idf * $tf * ($this->k1 + 1) / ($tf + $this->k1 * $norm);
                }
            }

            $hits[] = [$id, $score];
        }

        usort($hits, fn (array $a, array $b): int => $b[1] <=> $a[1] ?: strcmp($a[0], $b[0]));

        return ['hits' => array_slice($hits, $offset, $limit), 'total' => count($hits)];
    }
}
