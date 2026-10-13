<?php

declare(strict_types=1);

namespace PhpMiniSearch\Storage;

use Closure;

/**
 * The append-only log: the only durable state the service has. The index is
 * rebuilt from it on every start.
 *
 * open() replays the file and repairs what a crash can leave behind before
 * any write is accepted: a torn tail is cut off, and a damaged log is renamed
 * aside — kept for inspection, never deleted — and replaced by an empty one.
 * The caller learns which happened from recovery(), and decides what an
 * empty or lost log means for the corpus.
 *
 * append() returns only after the record is on disk: write, fflush, fsync.
 * Any failure is a StorageFailure, and the process is expected to stop.
 *
 * Only one process may hold the log: the service, or an offline `compact` or
 * `check`, never two at once. The exclusive flock() is on a separate
 * `<log>.lock` file, taken before anything is read or repaired, because
 * compaction renames a new file over the log and a lock on the old file would
 * not cover it.
 */
final class Log
{
    /**
     * @param resource $handle open for appending, positioned at the end
     * @param resource $lock the exclusive lock, held until close()
     */
    private function __construct(
        private $handle,
        private $lock,
        private readonly string $path,
        private readonly LogScan $recovery,
        private readonly ?string $damagedCopy,
    ) {
    }

    /**
     * @param Closure(array<string, mixed>): void $onRecord replays each valid record, in order
     */
    public static function open(string $path, Closure $onRecord): self
    {
        $lock = self::lock($path);
        $scan = LogScanner::scan($path, $onRecord);
        $damagedCopy = null;

        switch ($scan->status) {
            case LogScan::MISSING:
                self::create($path);
                break;
            case LogScan::TORN_TAIL:
                self::truncate($path, $scan->validBytes);
                break;
            case LogScan::DAMAGED:
                $damagedCopy = $path . '.damaged-' . date('Ymd-His');
                if (!rename($path, $damagedCopy)) {
                    throw new StorageFailure("Could not move the damaged log aside to {$damagedCopy}.");
                }
                self::create($path);
                break;
        }

        $handle = @fopen($path, 'ab');
        if ($handle === false) {
            throw new StorageFailure("Could not open the log {$path} for appending.");
        }

        return new self($handle, $lock, $path, $scan, $damagedCopy);
    }

    /**
     * Reads the log without changing it, under the same lock as open(), so a
     * running service's half-written record is never mistaken for a torn
     * tail. This is `bin/mini-search check`.
     */
    public static function inspect(string $path): LogScan
    {
        if (!is_dir(dirname($path))) {
            return new LogScan(LogScan::MISSING, 0, 0, 0);
        }

        $lock = self::lock($path);

        try {
            return LogScanner::scan($path, fn () => null);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Replaces the log with exactly these records: written to a temporary
     * file next to it, synced, renamed over it, and the directory synced. A
     * crash at any step leaves one whole log, the old one or the new one.
     * Only for a stopped service — this is offline compaction.
     *
     * @param iterable<array<string, mixed>> $records
     */
    public static function rewrite(string $path, iterable $records): void
    {
        $temporary = $path . '.compact';
        $handle = @fopen($temporary, 'wb');
        if ($handle === false) {
            throw new StorageFailure("Could not create {$temporary}.");
        }

        try {
            Durable::write($handle, LogFormat::header(), 'the compacted log');
            foreach ($records as $record) {
                Durable::write($handle, LogFormat::frame($record), 'the compacted log');
            }
            Durable::sync($handle, 'the compacted log');
        } finally {
            fclose($handle);
        }

        if (!rename($temporary, $path)) {
            throw new StorageFailure("Could not replace {$path} with the compacted log.");
        }
        Durable::syncDirectory(dirname($path));
    }

    /**
     * @param array<string, mixed> $record
     */
    public function append(array $record): void
    {
        Durable::write($this->handle, LogFormat::frame($record), 'a log record');
        Durable::sync($this->handle, 'the log');
    }

    public function recovery(): LogScan
    {
        return $this->recovery;
    }

    /** Where a damaged log was moved, if it was. */
    public function damagedCopy(): ?string
    {
        return $this->damagedCopy;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function close(): void
    {
        fclose($this->handle);
        flock($this->lock, LOCK_UN);
        fclose($this->lock);
    }

    /**
     * @return resource
     */
    private static function lock(string $path)
    {
        self::ensureDirectory(dirname($path));

        $lock = @fopen($path . '.lock', 'c');
        if ($lock === false) {
            throw new StorageFailure("Could not open the lock file {$path}.lock.");
        }
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);

            throw new StorageFailure("The log {$path} is in use by another process (the service, compact or check).");
        }

        return $lock;
    }

    private static function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !@mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new StorageFailure("Could not create the data directory {$directory}.");
        }
    }

    private static function create(string $path): void
    {
        $directory = dirname($path);
        self::ensureDirectory($directory);

        $handle = @fopen($path, 'wb');
        if ($handle === false) {
            throw new StorageFailure("Could not create the log {$path}.");
        }

        try {
            Durable::write($handle, LogFormat::header(), 'the log header');
            Durable::sync($handle, 'the new log');
        } finally {
            fclose($handle);
        }

        Durable::syncDirectory($directory);
    }

    private static function truncate(string $path, int $length): void
    {
        $handle = @fopen($path, 'r+b');
        if ($handle === false || !ftruncate($handle, max(0, $length))) {
            throw new StorageFailure("Could not cut the torn tail off {$path}.");
        }

        try {
            Durable::sync($handle, 'the truncated log');
        } finally {
            fclose($handle);
        }
    }
}
