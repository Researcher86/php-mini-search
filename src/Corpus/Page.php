<?php

declare(strict_types=1);

namespace PhpMiniSearch\Corpus;

final readonly class Page
{
    /**
     * @param list<Hit> $hits best first
     */
    public function __construct(
        public array $hits,
        public int $total,
    ) {
    }
}
