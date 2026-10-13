<?php

declare(strict_types=1);

namespace PhpMiniSearch\Http;

/**
 * An HTTP response, serialized with an exact Content-Length every time:
 * every response this service sends is small and fully built before it is
 * written, so there is never a reason for chunked encoding.
 */
final readonly class Response
{
    private const array REASONS = [
        100 => 'Continue', 200 => 'OK', 400 => 'Bad Request', 401 => 'Unauthorized',
        404 => 'Not Found', 405 => 'Method Not Allowed', 408 => 'Request Timeout',
        409 => 'Conflict', 411 => 'Length Required', 413 => 'Content Too Large',
        415 => 'Unsupported Media Type', 422 => 'Unprocessable Content',
        431 => 'Request Header Fields Too Large', 500 => 'Internal Server Error',
        501 => 'Not Implemented', 503 => 'Service Unavailable',
    ];

    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public int $status,
        public array $headers = [],
        public string $body = '',
    ) {
    }

    /**
     * @param array<mixed> $data
     * @param array<string, string> $headers
     */
    public static function json(int $status, array $data, array $headers = []): self
    {
        $body = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);

        return new self($status, ['Content-Type' => 'application/json'] + $headers, $body);
    }

    /**
     * @param bool $keepAlive false adds `Connection: close`
     * @param bool $withBody false for HEAD: the headers describe the body, which is not sent
     */
    public function serialize(bool $keepAlive, bool $withBody = true, bool $http10 = false): string
    {
        $headers = $this->headers;
        $headers['Content-Length'] = (string) strlen($this->body);
        $headers['Date'] = gmdate('D, d M Y H:i:s') . ' GMT';
        if (!$keepAlive) {
            $headers['Connection'] = 'close';
        } elseif ($http10) {
            $headers['Connection'] = 'keep-alive';
        }

        $head = sprintf("HTTP/1.1 %d %s\r\n", $this->status, self::REASONS[$this->status] ?? 'Unknown');
        foreach ($headers as $name => $value) {
            $head .= "{$name}: {$value}\r\n";
        }

        return $head . "\r\n" . ($withBody ? $this->body : '');
    }
}
