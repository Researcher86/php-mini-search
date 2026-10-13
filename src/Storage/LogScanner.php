<?php

declare(strict_types=1);

namespace PhpMiniSearch\Storage;

use Closure;

/**
 * Reads the log front to back, hands each valid record to a callback, and
 * decides what the end of the file means. It never writes.
 *
 * The one judgement that matters is torn tail versus damage. Every record is
 * fsynced before it is acknowledged, so a crash can leave at most one record
 * unfinished, and only the last one. Hence:
 *
 *  - the last record short, or failing its checksum → a torn tail: it was
 *    never acknowledged and is safe to cut;
 *  - a length above LogFormat::MAX_BODY → damage, wherever it is: a damaged
 *    length field in the middle could otherwise point past the end of the
 *    file and pass for a torn tail, and cutting there would silently drop
 *    every acknowledged record after it;
 *  - a checksum failure before the last record, or a valid checksum around a
 *    body that is not a JSON object → damage.
 *
 * A file shorter than the header that is a prefix of it was cut off while
 * being created, before anything was ever written to it: it counts as
 * missing.
 */
final class LogScanner
{
    /**
     * @param Closure(array<string, mixed>): void $onRecord called for each valid record, in order
     */
    public static function scan(string $path, Closure $onRecord): LogScan
    {
        if (!is_file($path)) {
            return new LogScan(LogScan::MISSING, 0, 0, 0);
        }

        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new StorageFailure("Could not open the log {$path}.");
        }

        try {
            return self::read($handle, (int) filesize($path), $onRecord);
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param resource $handle
     * @param Closure(array<string, mixed>): void $onRecord
     */
    private static function read($handle, int $size, Closure $onRecord): LogScan
    {
        $header = LogFormat::header();
        $headerLength = strlen($header);
        $start = self::bytes($handle, $headerLength);

        if ($size < $headerLength) {
            return str_starts_with($header, $start)
                ? new LogScan(LogScan::MISSING, 0, 0, $size)
                : new LogScan(LogScan::DAMAGED, 0, 0, $size, 'not a log: bad header');
        }
        if ($start !== $header) {
            return new LogScan(LogScan::DAMAGED, 0, 0, $size, 'not a log, or an unknown format version: bad header');
        }

        $offset = $headerLength;
        $records = 0;

        while ($offset < $size) {
            $remaining = $size - $offset;
            if ($remaining < LogFormat::FRAME_HEADER) {
                return new LogScan(LogScan::TORN_TAIL, $records, $offset, $size, "{$remaining} bytes of an unfinished record header");
            }

            /** @var array{1: int, 2: int} $frame */
            $frame = unpack('N2', self::bytes($handle, LogFormat::FRAME_HEADER));
            [1 => $length, 2 => $checksum] = $frame;

            if ($length > LogFormat::MAX_BODY) {
                return new LogScan(LogScan::DAMAGED, $records, $offset, $size, "record at byte {$offset} claims {$length} bytes");
            }

            $end = $offset + LogFormat::FRAME_HEADER + $length;
            if ($end > $size) {
                return new LogScan(LogScan::TORN_TAIL, $records, $offset, $size, "record at byte {$offset} is cut short");
            }

            $body = self::bytes($handle, $length);
            if (crc32($body) !== $checksum) {
                return $end === $size
                    ? new LogScan(LogScan::TORN_TAIL, $records, $offset, $size, "last record at byte {$offset} fails its checksum")
                    : new LogScan(LogScan::DAMAGED, $records, $offset, $size, "record at byte {$offset} fails its checksum");
            }

            $record = LogFormat::decode($body);
            if ($record === null) {
                return new LogScan(LogScan::DAMAGED, $records, $offset, $size, "record at byte {$offset} is not a JSON object");
            }

            $onRecord($record);
            $records++;
            $offset = $end;
        }

        return new LogScan(LogScan::CLEAN, $records, $offset, $size);
    }

    /**
     * @param resource $handle
     */
    private static function bytes($handle, int $length): string
    {
        if ($length === 0) {
            return '';
        }

        $bytes = '';
        while (strlen($bytes) < $length && !feof($handle)) {
            $chunk = fread($handle, max(1, $length - strlen($bytes)));
            if ($chunk === false || $chunk === '') {
                break;
            }
            $bytes .= $chunk;
        }

        return $bytes;
    }
}
