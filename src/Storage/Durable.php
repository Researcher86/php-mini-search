<?php

declare(strict_types=1);

namespace PhpMiniSearch\Storage;

/**
 * The few file operations whose durability the log depends on, written once
 * with every check PHP allows. The recipe was verified against php-src and
 * strace on PHP 8.5 (docs/ARCHITECTURE.md, Storage):
 *
 *  - fflush() before fsync(): after the first fsync() PHP buffers the stream
 *    in libc, and a write error only surfaces at the next sync;
 *  - a failed fsync() returns a bare false, with no errno and no warning;
 *  - a directory is synced through fopen($dir, 'r');
 *  - rename() is atomic only within one filesystem — across devices PHP
 *    silently copies without syncing — so temporary files live next to the
 *    file they replace.
 *
 * On macOS PHP does not use F_FULLFSYNC, so none of this survives a power
 * cut there; it does on Linux.
 */
final class Durable
{
    /**
     * @param resource $handle
     */
    public static function sync($handle, string $what): void
    {
        if (!@fflush($handle) || !@fsync($handle)) {
            throw new StorageFailure("Could not sync {$what}." . self::lastError());
        }
    }

    public static function syncDirectory(string $directory): void
    {
        $handle = @fopen($directory, 'r');
        if ($handle === false) {
            throw new StorageFailure("Could not open directory {$directory} to sync it.");
        }

        try {
            if (!fsync($handle)) {
                throw new StorageFailure("Could not sync directory {$directory}.");
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param resource $handle
     */
    public static function write($handle, string $bytes, string $what): void
    {
        $written = 0;
        $length = strlen($bytes);

        while ($written < $length) {
            // @: PHP reports a failed write as a notice as well; the
            // exception carries the same message, and the caller stops.
            $result = @fwrite($handle, substr($bytes, $written));
            if ($result === false || $result === 0) {
                throw new StorageFailure("Could not write {$what}." . self::lastError());
            }
            $written += $result;
        }
    }

    private static function lastError(): string
    {
        $error = error_get_last();

        return $error === null ? '' : ' ' . $error['message'];
    }
}
