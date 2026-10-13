<?php

declare(strict_types=1);

namespace PhpMiniSearch\Corpus;

/**
 * One search result as the API returns it: the platform's id, a relative
 * score, and the version of the row the index holds, so the platform can tell
 * when its database is ahead of the index.
 */
final readonly class Hit
{
    public function __construct(
        public string $id,
        public float $score,
        public int $sourceVersion,
    ) {
    }
}
