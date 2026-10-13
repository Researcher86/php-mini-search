<?php

declare(strict_types=1);

namespace PhpMiniSearch\Tests\Index;

use InvalidArgumentException;
use PhpMiniSearch\Index\InvertedIndex;
use PhpMiniSearch\Index\SearchResult;
use PHPUnit\Framework\TestCase;

/**
 * The behaviours worth naming one by one. The random comparison with the
 * oracle covers the arithmetic; these tests say what the arithmetic is for.
 */
final class InvertedIndexTest extends TestCase
{
    private InvertedIndex $index;

    protected function setUp(): void
    {
        $this->index = new InvertedIndex();
    }

    public function testAQueryMatchesOnlyDocumentsWithEveryTerm(): void
    {
        $this->index->put('a', ['title' => ['usb', 'кабел']]);
        $this->index->put('b', ['title' => ['usb']]);
        $this->index->put('c', ['title' => ['кабел']]);

        $this->assertSame(['a'], $this->ids($this->index->search(['кабел', 'usb'], 10)));
    }

    public function testTermsMayBeSplitAcrossFields(): void
    {
        $this->index->put('a', ['title' => ['кабел'], 'body' => ['usb']]);

        $this->assertSame(['a'], $this->ids($this->index->search(['кабел', 'usb'], 10)));
    }

    public function testReplacingADocumentForgetsItsOldTerms(): void
    {
        $this->index->put('a', ['title' => ['usb']]);
        $this->index->put('a', ['title' => ['hdmi']]);

        $this->assertSame([], $this->ids($this->index->search(['usb'], 10)));
        $this->assertSame(['a'], $this->ids($this->index->search(['hdmi'], 10)));
        $this->assertSame(1, $this->index->documentCount());
        $this->assertSame(1, $this->index->termCount());
    }

    public function testDeletingADocumentRemovesItEverywhere(): void
    {
        $this->index->put('a', ['title' => ['usb'], 'body' => ['usb', 'кабел']]);
        $this->index->delete('a');

        $this->assertFalse($this->index->has('a'));
        $this->assertSame(0, $this->index->termCount());
        $this->assertSame(0, $this->index->search(['usb'], 10)->total);
        $this->assertSame([], $this->index->invariantViolations());
    }

    public function testDeletingAnUnknownDocumentIsHarmless(): void
    {
        $this->index->delete('never-added');

        $this->assertSame(0, $this->index->documentCount());
    }

    public function testATitleMatchOutranksTheSameMatchInTheBody(): void
    {
        $this->index->put('in-body', ['title' => ['other'], 'body' => ['usb']]);
        $this->index->put('in-title', ['title' => ['usb'], 'body' => ['other']]);

        $this->assertSame(['in-title', 'in-body'], $this->ids($this->index->search(['usb'], 10)));
    }

    public function testAShorterDocumentOutranksALongerOneWithTheSameMatch(): void
    {
        $this->index->put('long', ['body' => ['usb', 'a', 'b', 'c', 'd', 'e', 'f', 'g']]);
        $this->index->put('short', ['body' => ['usb', 'a']]);

        $this->assertSame(['short', 'long'], $this->ids($this->index->search(['usb'], 10)));
    }

    public function testEqualScoresAreOrderedById(): void
    {
        foreach (['c', 'a', 'b'] as $id) {
            $this->index->put($id, ['title' => ['usb']]);
        }

        $this->assertSame(['a', 'b', 'c'], $this->ids($this->index->search(['usb'], 10)));
    }

    public function testATermInEveryDocumentStillScoresAboveZero(): void
    {
        $this->index->put('a', ['title' => ['usb']]);
        $this->index->put('b', ['title' => ['usb']]);

        $this->assertGreaterThan(0.0, $this->index->search(['usb'], 10)->hits[0]->score);
    }

    public function testPagesSliceTheRankingAndTotalCountsEveryMatch(): void
    {
        foreach (['a', 'b', 'c', 'd', 'e'] as $id) {
            $this->index->put($id, ['title' => ['usb']]);
        }

        $page = $this->index->search(['usb'], 2, 2);

        $this->assertSame(['c', 'd'], $this->ids($page));
        $this->assertSame(5, $page->total);
    }

    public function testQueryOrderAndDuplicatesDoNotChangeTheResult(): void
    {
        $this->index->put('a', ['title' => ['usb', 'кабел'], 'body' => ['usb']]);

        $this->assertEquals($this->index->search(['usb', 'кабел'], 10), $this->index->search(['кабел', 'usb', 'usb'], 10));
    }

    public function testAnEmptyQueryOrAnUnknownTermMatchesNothing(): void
    {
        $this->index->put('a', ['title' => ['usb']]);

        $this->assertSame(0, $this->index->search([], 10)->total);
        $this->assertSame(0, $this->index->search(['usb', 'missing'], 10)->total);
    }

    public function testDescribeShowsWhatTheIndexHoldsForADocument(): void
    {
        $this->index->put('a', ['title' => ['usb', 'кабел', 'usb'], 'body' => []]);

        $this->assertSame(
            ['title' => ['length' => 3, 'terms' => ['usb' => 2, 'кабел' => 1]], 'body' => ['length' => 0, 'terms' => []]],
            $this->index->describe('a'),
        );
        $this->assertNull($this->index->describe('missing'));
    }

    public function testAnUnknownFieldIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->index->put('a', ['description' => ['usb']]);
    }

    /**
     * @return list<string>
     */
    private function ids(SearchResult $result): array
    {
        return array_map(fn ($hit) => $hit->id, $result->hits);
    }
}
