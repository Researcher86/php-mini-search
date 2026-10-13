<?php

declare(strict_types=1);

namespace PhpMiniSearch\Index;

use SplHeap;

/**
 * Keeps the best k hits seen so far, so a page of results costs
 * O(n log k) instead of sorting every match.
 *
 * The heap is upside down on purpose: its top is the *worst* hit it holds,
 * so a new hit only has to beat the top to get in, and evicting is one
 * extract(). "Better" means a higher score, and on equal scores a smaller id
 * — the same tie-break the API promises, so equal scores still come out in a
 * fixed order.
 *
 * @extends SplHeap<SearchHit>
 */
final class TopHits extends SplHeap
{
    public function __construct(
        private readonly int $capacity,
    ) {
    }

    public function offer(SearchHit $hit): void
    {
        if ($this->count() < $this->capacity) {
            $this->insert($hit);

            return;
        }

        if ($this->capacity > 0 && self::better($hit, $this->top())) {
            $this->extract();
            $this->insert($hit);
        }
    }

    /**
     * @return list<SearchHit> best first
     */
    public function best(): array
    {
        $hits = [];
        foreach (clone $this as $hit) {
            $hits[] = $hit;
        }

        return array_reverse($hits);
    }

    /**
     * SplHeap puts the element that compares greatest on top; here that is
     * the worst hit.
     *
     * @param SearchHit $value1
     * @param SearchHit $value2
     */
    protected function compare(mixed $value1, mixed $value2): int
    {
        if (self::better($value1, $value2)) {
            return -1;
        }

        return self::better($value2, $value1) ? 1 : 0;
    }

    private static function better(SearchHit $a, SearchHit $b): bool
    {
        if ($a->score !== $b->score) {
            return $a->score > $b->score;
        }

        return strcmp($a->id, $b->id) < 0;
    }
}
