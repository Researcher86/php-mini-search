<?php

declare(strict_types=1);

namespace PhpMiniSearch\Tests\Index;

use PhpMiniSearch\Index\InvertedIndex;
use PhpMiniSearch\Tests\Support\NaiveSearch;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Random puts, replacements, deletes and searches, run against the index and
 * against the naive oracle side by side. Every search must return the same
 * ids in the same order, with the same scores, and the index must pass its
 * invariant check after every single operation.
 *
 * The vocabulary is small and skewed, and ids come from a small pool, so terms
 * collide constantly, documents are replaced over and over, and ties in score
 * are common — the cases where stale postings, wrong statistics or a sloppy
 * tie-break show up. A quarter of the writes copy an earlier document under a
 * new id: random documents almost never tie to the last bit, and without
 * copies a reversed tie-break passed this test unnoticed. A failure names its
 * seed and step, so it can be replayed.
 */
final class InvertedIndexAgainstOracleTest extends TestCase
{
    private const int OPERATIONS_PER_SEED = 2_500;

    /**
     * @return iterable<string, array{int}>
     */
    public static function seeds(): iterable
    {
        foreach ([1, 2, 3, 4, 5] as $seed) {
            yield "seed {$seed}" => [$seed];
        }
    }

    #[DataProvider('seeds')]
    public function testIndexAgreesWithTheOracle(int $seed): void
    {
        mt_srand($seed);
        $index = new InvertedIndex();
        $oracle = new NaiveSearch();
        $searches = 0;
        $ties = 0;
        /** @var list<array<string, list<string>>> $written */
        $written = [];

        for ($step = 0; $step < self::OPERATIONS_PER_SEED; $step++) {
            $where = "seed {$seed}, step {$step}";
            $roll = mt_rand(1, 100);

            if ($roll <= 45) {
                $id = $this->randomId();
                // Now and then an exact copy of an earlier document, so that
                // equal scores are common and the tie-break is exercised.
                $fields = $written !== [] && mt_rand(1, 4) === 1
                    ? $written[mt_rand(0, count($written) - 1)]
                    : ['title' => $this->randomTerms(0, 6), 'body' => $this->randomTerms(0, 25)];
                $written[] = $fields;
                $index->put($id, $fields);
                $oracle->put($id, $fields);
            } elseif ($roll <= 60) {
                $id = $this->randomId();
                $index->delete($id);
                $oracle->delete($id);
            } else {
                $searches++;
                $terms = $this->randomTerms(1, 3);
                $limit = mt_rand(1, 10);
                $offset = mt_rand(0, 5);

                $actual = $index->search($terms, $limit, $offset);
                $expected = $oracle->search($terms, $limit, $offset);

                $this->assertSame($expected['total'], $actual->total, "{$where}: total");
                $this->assertSame(
                    array_column($expected['hits'], 0),
                    array_map(fn ($hit) => $hit->id, $actual->hits),
                    "{$where}: ids and order for " . implode(' ', $terms),
                );
                $scores = array_column($expected['hits'], 1);
                $ties += count($scores) - count(array_unique(array_map('strval', $scores)));
                foreach ($actual->hits as $i => $hit) {
                    $this->assertEqualsWithDelta($expected['hits'][$i][1], $hit->score, 1e-9, "{$where}: score of {$hit->id}");
                }
            }

            $this->assertSame([], $index->invariantViolations(), "{$where}: invariants");
        }

        // The comparison is only as strong as what it compared.
        $this->assertGreaterThan(500, $searches);
        $this->assertGreaterThan(100, $ties, 'too few equal scores to exercise the tie-break');
    }

    private function randomId(): string
    {
        return 'doc-' . mt_rand(1, 40);
    }

    /**
     * Skewed towards the first terms, so some are in most documents and some
     * in almost none. Includes numeric terms, which PHP turns into integer
     * array keys, and a Cyrillic one.
     *
     * @return list<string>
     */
    private function randomTerms(int $min, int $max): array
    {
        $vocabulary = ['usb', 'кабел', '3', 'cabl', 'black', 'red', '42', 'fast', 'charg', 'adapt', 'long', 'short', 'pro', 'mini', 'max', 'lite'];
        $terms = [];

        for ($i = mt_rand($min, $max); $i > 0; $i--) {
            $position = (int) floor((mt_rand() / mt_getrandmax()) ** 2 * count($vocabulary));
            $terms[] = $vocabulary[min($position, count($vocabulary) - 1)];
        }

        return $terms;
    }
}
