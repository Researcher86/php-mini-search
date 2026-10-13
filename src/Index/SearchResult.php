<?php

declare(strict_types=1);

namespace PhpMiniSearch\Index;

/**
 * One page of hits, plus how many documents matched in total.
 */
final readonly class SearchResult
{
    /**
     * @param list<SearchHit> $hits best first
     * @param int $total every document that matched, not only this page
     */
    public function __construct(
        public array $hits,
        public int $total,
    ) {
    }
}
