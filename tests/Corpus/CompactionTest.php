<?php

declare(strict_types=1);

namespace PhpMiniSearch\Tests\Corpus;

use PhpMiniSearch\Corpus\Compaction;
use PhpMiniSearch\Corpus\Corpus;
use PhpMiniSearch\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The one property compaction has to keep: replaying the compacted log
 * gives exactly the corpus that replaying the original gave — documents,
 * source versions, generations, tombstones, the generation counter, an open
 * reindex, and whether a reindex has ever completed.
 *
 * The history is random, with puts, stale puts, deletes, and reindexes that
 * are completed, cancelled or left open, so that every kind of state that
 * only history knows is there to be lost.
 */
final class CompactionTest extends TestCase
{
    private TemporaryDirectory $directory;

    protected function setUp(): void
    {
        $this->directory = new TemporaryDirectory();
    }

    protected function tearDown(): void
    {
        $this->directory->remove();
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function seeds(): iterable
    {
        foreach ([1, 2, 3, 4, 5, 6, 7, 8] as $seed) {
            yield "seed {$seed}" => [$seed];
        }
    }

    #[DataProvider('seeds')]
    public function testACompactedLogReplaysToTheSameCorpus(int $seed): void
    {
        $path = $this->directory->path('search.log');
        mt_srand($seed);
        $corpus = Corpus::open($path);
        $versions = [];
        $words = ['кабель', 'usb', 'адаптер', 'зарядка', 'черный', 'fast', 'lightning'];
        $text = fn (int $n) => implode(' ', array_map(fn () => $words[mt_rand(0, count($words) - 1)], range(1, $n)));

        for ($step = 0; $step < 300; $step++) {
            $id = 'SKU-' . mt_rand(1, 25);
            $open = $corpus->stats()['open_generation'];
            $roll = mt_rand(1, 100);

            if ($roll <= 55) {
                // Usually newer, sometimes stale; during a reindex, sometimes from the reindex itself.
                $version = ($versions[$id] ?? 0) + mt_rand(-1, 2);
                $versions[$id] = max($versions[$id] ?? 0, $version);
                $corpus->put($id, max(1, $version), $text(mt_rand(1, 4)), $text(mt_rand(0, 12)), $open !== null && mt_rand(0, 1) === 1 ? $open : null);
            } elseif ($roll <= 75) {
                $versions[$id] = ($versions[$id] ?? 0) + 1;
                $corpus->delete($id, $versions[$id]);
            } elseif ($roll <= 85) {
                $corpus->beginReindex();
            } elseif ($open !== null) {
                $corpus->completeReindex($open);
            }
        }

        $expected = $corpus->state();
        $corpus->close();

        $sizes = Compaction::run($path);
        $compacted = Corpus::open($path);

        $this->assertSame($expected, $compacted->state());
        $this->assertLessThan($sizes['bytes_before'], $sizes['bytes_after']);
        $compacted->close();
    }

    public function testCompactingTwiceChangesNothing(): void
    {
        $path = $this->directory->path('search.log');
        $corpus = Corpus::open($path);
        $corpus->put('a', 1, 'кабель', '');
        $corpus->delete('b', 1);
        $corpus->beginReindex();
        $corpus->close();

        Compaction::run($path);
        $once = file_get_contents($path);
        Compaction::run($path);

        $this->assertSame($once, file_get_contents($path));
    }
}
