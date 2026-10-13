<?php

declare(strict_types=1);

namespace PhpMiniSearch\Corpus;

use RuntimeException;

/**
 * complete() named a generation that is not the open one: already completed,
 * cancelled by a newer reindex, or never begun. Completing it would remove
 * documents on the word of a reindex that is no longer running.
 */
final class GenerationNotOpen extends RuntimeException
{
}
