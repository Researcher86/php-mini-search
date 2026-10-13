<?php

declare(strict_types=1);

namespace PhpMiniSearch\Corpus;

use RuntimeException;

/**
 * Search was asked of a corpus that was never filled, or whose log was lost.
 * Answering "no results" would look like a working service; this is a 503
 * until a reindex completes.
 */
final class ReindexRequired extends RuntimeException
{
    public const string FIRST_START = 'first_start';

    public const string LOG_CORRUPTED = 'log_corrupted';

    /**
     * @param self::FIRST_START|self::LOG_CORRUPTED $reason
     */
    public function __construct(
        public readonly string $reason,
    ) {
        parent::__construct("A full reindex is required ({$reason}).");
    }
}
