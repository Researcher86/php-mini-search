<?php

declare(strict_types=1);

namespace PhpMiniSearch\Index;

/**
 * One ranked match. The score is relative: it orders the hits of one query
 * and means nothing on its own scale.
 */
final readonly class SearchHit
{
    public function __construct(
        public string $id,
        public float $score,
    ) {
    }
}
