<?php

declare(strict_types=1);

namespace PhpMiniSearch\Storage;

use RuntimeException;

/**
 * The log could not be written or synced. The caller answers 503 and stops
 * the process (docs/FAILURE-MODEL.md): after a failed fsync the kernel may
 * already have dropped the dirty pages, so retrying proves nothing, and only a
 * restart and a replay of what is really on disk restores a known state.
 */
final class StorageFailure extends RuntimeException
{
}
