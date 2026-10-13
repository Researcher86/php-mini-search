<?php

declare(strict_types=1);

namespace PhpMiniSearch\Runtime;

use DateTimeImmutable;

/**
 * One JSON object per line on stderr: what happened, never what was said.
 * Request bodies, document text and the token are never logged.
 *
 * At level "info" every request is a line; at "error" only failures (5xx) and
 * requests slower than the threshold are. The write is synchronous — a slow
 * stderr pipe blocks the loop, a risk documented in docs/FAILURE-MODEL.md
 * rather than solved here.
 */
final class Logger
{
    public const string INFO = 'info';

    public const string ERROR = 'error';

    /**
     * @param resource $stream
     * @param self::INFO|self::ERROR $level
     */
    public function __construct(
        private readonly mixed $stream = STDERR,
        private readonly string $level = self::INFO,
        private readonly float $slowMilliseconds = 100.0,
    ) {
    }

    public function request(string $method, string $path, int $status, float $milliseconds, string $requestId): void
    {
        if ($this->level === self::ERROR && $status < 500 && $milliseconds < $this->slowMilliseconds) {
            return;
        }

        $this->write($status >= 500 ? self::ERROR : self::INFO, 'request', [
            'method' => $method,
            'path' => $path,
            'status' => $status,
            'ms' => round($milliseconds, 3),
            'request_id' => $requestId,
        ]);
    }

    /**
     * @param array<string, scalar|null> $context
     */
    public function info(string $event, array $context = []): void
    {
        if ($this->level === self::INFO) {
            $this->write(self::INFO, $event, $context);
        }
    }

    /**
     * @param array<string, scalar|null> $context
     */
    public function error(string $event, array $context = []): void
    {
        $this->write(self::ERROR, $event, $context);
    }

    /**
     * @param array<string, scalar|null> $context
     */
    private function write(string $level, string $event, array $context): void
    {
        $line = ['time' => (new DateTimeImmutable())->format('Y-m-d\TH:i:s.vP'), 'level' => $level, 'event' => $event] + $context;
        @fwrite($this->stream, json_encode($line, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n");
    }
}
