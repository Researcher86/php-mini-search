<?php

declare(strict_types=1);

namespace PhpMiniSearch\Tests\Corpus;

use PhpMiniSearch\Corpus\Corpus;
use PhpMiniSearch\Corpus\GenerationNotOpen;
use PhpMiniSearch\Corpus\Page;
use PhpMiniSearch\Corpus\QueryRejected;
use PhpMiniSearch\Corpus\ReindexRequired;
use PhpMiniSearch\Storage\Log;
use PhpMiniSearch\Storage\StorageFailure;
use PhpMiniSearch\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Each scenario from docs/FAILURE-MODEL.md that Corpus is responsible for,
 * by name. Most of them also restart the corpus, because a rule that holds
 * in memory but not after a replay holds nowhere.
 */
final class CorpusTest extends TestCase
{
    private TemporaryDirectory $directory;

    private string $path;

    protected function setUp(): void
    {
        $this->directory = new TemporaryDirectory();
        $this->path = $this->directory->path('search.log');
    }

    protected function tearDown(): void
    {
        $this->directory->remove();
    }

    public function testAFirstStartRequiresAReindexBeforeSearching(): void
    {
        $corpus = Corpus::open($this->path);

        $this->assertSame(ReindexRequired::FIRST_START, $corpus->reindexRequired());
        $this->expectException(ReindexRequired::class);
        $corpus->search('usb', 10);
    }

    public function testAReindexOfNothingMakesAnEmptyCorpusReady(): void
    {
        $corpus = Corpus::open($this->path);
        $corpus->completeReindex($corpus->beginReindex()['generation']);

        $this->assertNull($corpus->reindexRequired());
        $this->assertSame(0, $corpus->search('usb', 10)->total);
    }

    public function testWritesAreSearchableAndCarryTheirSourceVersion(): void
    {
        $corpus = $this->readyCorpus();
        $corpus->put('SKU-1', 3, 'Кабель USB-C', 'Быстрая зарядка');

        $page = $corpus->search('кабели usb', 10);

        $this->assertSame(1, $page->total);
        $this->assertSame('SKU-1', $page->hits[0]->id);
        $this->assertSame(3, $page->hits[0]->sourceVersion);
    }

    public function testAnOlderOrEqualVersionIsStaleAndChangesNothing(): void
    {
        $corpus = $this->readyCorpus();
        $corpus->put('SKU-1', 5, 'Кабель USB-C', '');

        $older = $corpus->put('SKU-1', 4, 'Кабель USB', '');
        $equal = $corpus->put('SKU-1', 5, 'Что-то другое', '');

        $this->assertFalse($older->applied);
        $this->assertSame(5, $older->sourceVersion);
        $this->assertFalse($equal->applied);
        $this->assertSame(1, $corpus->search('кабель', 10)->total);
    }

    public function testALateUpdateCannotResurrectADeletedDocument(): void
    {
        $corpus = $this->readyCorpus();
        $corpus->put('SKU-7', 17, 'Кабель', '');
        $corpus->delete('SKU-7', 18);

        $late = $corpus->put('SKU-7', 17, 'Кабель', '');

        $this->assertFalse($late->applied);
        $this->assertSame(0, $corpus->search('кабель', 10)->total);
    }

    public function testADeleteThatArrivesBeforeItsPutStillWins(): void
    {
        $corpus = $this->readyCorpus();

        $this->assertTrue($corpus->delete('SKU-9', 2)->applied);
        $this->assertFalse($corpus->put('SKU-9', 1, 'Кабель', '')->applied);
        $this->assertSame(['id' => 'SKU-9', 'state' => 'deleted', 'source_version' => 2], $corpus->describe('SKU-9'));
    }

    public function testANewerPutRecreatesADeletedDocument(): void
    {
        $corpus = $this->readyCorpus();
        $corpus->delete('SKU-9', 2);

        $this->assertTrue($corpus->put('SKU-9', 3, 'Кабель', '')->applied);
        $this->assertSame(1, $corpus->search('кабель', 10)->total);
    }

    public function testCompletingAReindexRemovesWhatItDidNotTouch(): void
    {
        $corpus = $this->readyCorpus();
        $corpus->put('kept', 1, 'кабель', '');
        $corpus->put('gone', 1, 'кабель', '');

        $generation = $corpus->beginReindex()['generation'];
        $corpus->put('kept', 1, 'кабель', '', $generation);

        $this->assertSame(1, $corpus->completeReindex($generation));
        $this->assertSame(['kept'], $this->ids($corpus->search('кабель', 10)));
        $this->assertSame('deleted', $corpus->describe('gone')['state'] ?? null);
    }

    /**
     * The reindex has already passed the product's key when it is created;
     * the ordinary job writes it without naming the generation.
     */
    public function testADocumentCreatedDuringAReindexSurvivesItsCompletion(): void
    {
        $corpus = $this->readyCorpus();
        $generation = $corpus->beginReindex()['generation'];

        $result = $corpus->put('new', 1, 'кабель', '');
        $corpus->completeReindex($generation);

        $this->assertSame($generation, $result->generation);
        $this->assertSame(['new'], $this->ids($corpus->search('кабель', 10)));
    }

    public function testAStalePutFromTheReindexConfirmsTheDocumentAcrossARestart(): void
    {
        $corpus = $this->readyCorpus();
        $corpus->put('same', 4, 'кабель', '');
        $generation = $corpus->beginReindex()['generation'];
        $this->assertFalse($corpus->put('same', 4, 'кабель', '', $generation)->applied);
        $corpus->close();

        $corpus = Corpus::open($this->path);
        $corpus->completeReindex($generation);

        $this->assertSame(['same'], $this->ids($corpus->search('кабель', 10)));
    }

    public function testANewReindexCancelsTheOpenOne(): void
    {
        $corpus = $this->readyCorpus();
        $first = $corpus->beginReindex()['generation'];

        $second = $corpus->beginReindex();

        $this->assertSame($first, $second['cancelled_generation']);
        $this->expectException(GenerationNotOpen::class);
        $corpus->completeReindex($first);
    }

    public function testAReindexInProgressSurvivesARestart(): void
    {
        $corpus = $this->readyCorpus();
        $generation = $corpus->beginReindex()['generation'];
        $corpus->put('a', 1, 'кабель', '', $generation);
        $corpus->close();

        $corpus = Corpus::open($this->path);

        $this->assertSame($generation, $corpus->stats()['open_generation']);
        $this->assertSame(0, $corpus->completeReindex($generation));
    }

    public function testEveryRuleHoldsAfterARestart(): void
    {
        $corpus = $this->readyCorpus();
        $corpus->put('a', 1, 'Кабель USB-C', 'Быстрая зарядка');
        $corpus->put('b', 1, 'Адаптер', 'usb');
        $corpus->put('a', 2, 'Кабель Lightning', '');
        $corpus->delete('b', 2);
        $corpus->delete('never-seen', 1);
        $before = $corpus->state();
        $corpus->close();

        $after = Corpus::open($this->path);

        $this->assertSame($before, $after->state());
        $this->assertFalse($after->put('b', 1, 'Адаптер', '')->applied);
    }

    public function testADamagedLogIsKeptAsideAndTheCorpusAsksForAReindex(): void
    {
        $corpus = $this->readyCorpus();
        $corpus->put('a', 1, 'кабель', '');
        $corpus->put('b', 1, 'кабель', '');
        $corpus->close();
        $bytes = (string) file_get_contents($this->path);
        $bytes[20] = chr(ord($bytes[20]) ^ 0xFF);
        file_put_contents($this->path, $bytes);

        $corpus = Corpus::open($this->path);

        $this->assertSame(ReindexRequired::LOG_CORRUPTED, $corpus->reindexRequired());
        $this->assertSame(0, $corpus->stats()['documents']);
        $this->assertCount(1, glob($this->path . '.damaged-*') ?: []);
    }

    public function testQueriesWithoutTermsOrWithTooManyAreRejected(): void
    {
        $corpus = $this->readyCorpus();

        $this->assertRejected(QueryRejected::EMPTY_QUERY, fn () => $corpus->search('!!! ...', 10));
        $this->assertRejected(QueryRejected::TOO_MANY_TERMS, fn () => $corpus->search(implode(' ', array_map(fn ($i) => "w{$i}", range(1, 33))), 10));
    }

    /**
     * A write the log could not take must never become visible: the index is
     * only touched after the append and the fsync succeed. The log's handle
     * is swapped for a read-only one, so the next fwrite() fails the way a
     * full disk would.
     */
    public function testAWriteTheLogRejectedIsNeverSearchable(): void
    {
        $corpus = $this->readyCorpus();
        $corpus->put('a', 1, 'кабель', '');
        $before = $corpus->state();

        $log = (new ReflectionProperty(Corpus::class, 'log'))->getValue($corpus);
        $this->assertInstanceOf(Log::class, $log);
        (new ReflectionProperty(Log::class, 'handle'))->setValue($log, fopen($this->path, 'rb'));

        try {
            $corpus->put('b', 1, 'кабель', '');
            $this->fail('The write should have failed.');
        } catch (StorageFailure) {
        }

        $this->assertSame($before, $corpus->state());
        $this->assertSame(['a'], $this->ids($corpus->search('кабель', 10)));
    }

    public function testTheLogCanOnlyBeOpenedByOneProcessAtATime(): void
    {
        $corpus = Corpus::open($this->path);

        try {
            $this->expectException(StorageFailure::class);
            Corpus::open($this->path);
        } finally {
            $corpus->close();
        }
    }

    private function readyCorpus(): Corpus
    {
        $corpus = Corpus::open($this->path);
        $corpus->completeReindex($corpus->beginReindex()['generation']);

        return $corpus;
    }

    /**
     * @return list<string>
     */
    private function ids(Page $page): array
    {
        return array_map(fn ($hit) => $hit->id, $page->hits);
    }

    private function assertRejected(string $reason, callable $search): void
    {
        try {
            $search();
            $this->fail("Expected the query to be rejected with {$reason}.");
        } catch (QueryRejected $rejected) {
            $this->assertSame($reason, $rejected->reason);
        }
    }
}
