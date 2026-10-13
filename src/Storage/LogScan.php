<?php

declare(strict_types=1);

namespace PhpMiniSearch\Storage;

/**
 * What a read of the log found. Reading never changes the file; acting on
 * the result is Log::open()'s job, and `bin/mini-search check` only prints it.
 */
final readonly class LogScan
{
    /** No log file: a first start. */
    public const string MISSING = 'missing';

    /** Every byte belongs to a valid record. */
    public const string CLEAN = 'clean';

    /** The last record is short or fails its checksum: a write that never finished, and so was never acknowledged. */
    public const string TORN_TAIL = 'torn_tail';

    /** A record that cannot be explained by an interrupted write. Truncating would drop acknowledged records. */
    public const string DAMAGED = 'damaged';

    /**
     * @param self::MISSING|self::CLEAN|self::TORN_TAIL|self::DAMAGED $status
     * @param int $validBytes where the last valid record ends; everything after it is the tail or the damage
     */
    public function __construct(
        public string $status,
        public int $records,
        public int $validBytes,
        public int $fileBytes,
        public ?string $reason = null,
    ) {
    }
}
