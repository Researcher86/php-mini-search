<?php

declare(strict_types=1);

namespace PhpMiniSearch\Corpus;

/**
 * What a PUT or DELETE did. A stale write is not an error: it reports
 * applied = false and the version the corpus already holds, so the job that
 * sent it can stop retrying.
 */
final readonly class WriteResult
{
    public function __construct(
        public string $id,
        public bool $applied,
        public int $sourceVersion,
        public ?int $generation,
    ) {
    }
}
