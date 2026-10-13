<?php

declare(strict_types=1);

namespace PhpMiniSearch\Storage;

use JsonException;

/**
 * The bytes of the log, and nothing else.
 *
 *   file   = "MSLOG" uint32(format version) record*
 *   record = uint32(body length) uint32(crc32 of body) body
 *   body   = one JSON object
 *
 * Both integers are big-endian (pack 'N'). The length and the checksum frame
 * a record, so its boundary never depends on what the JSON contains; the
 * body stays readable with `strings`.
 */
final class LogFormat
{
    public const string MAGIC = 'MSLOG';

    public const int VERSION = 1;

    /**
     * No valid record comes near this: a document is at most 1 KB of title
     * and 64 KB of body. A larger length is a damaged length field, never a
     * torn write (docs/FAILURE-MODEL.md).
     */
    public const int MAX_BODY = 262_144;

    public const int FRAME_HEADER = 8;

    public static function header(): string
    {
        return self::MAGIC . pack('N', self::VERSION);
    }

    /**
     * @param array<string, mixed> $record
     */
    public static function frame(array $record): string
    {
        $body = json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (strlen($body) > self::MAX_BODY) {
            throw new StorageFailure('Record of ' . strlen($body) . ' bytes is over the ' . self::MAX_BODY . '-byte limit.');
        }

        return pack('NN', strlen($body), crc32($body)) . $body;
    }

    /**
     * @return array<string, mixed>|null null when the body is not a JSON object
     */
    public static function decode(string $body): ?array
    {
        try {
            $record = json_decode($body, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (!is_array($record) || array_is_list($record)) {
            return null;
        }

        /** @var array<string, mixed> $record */
        return $record;
    }
}
