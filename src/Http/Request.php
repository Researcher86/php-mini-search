<?php

declare(strict_types=1);

namespace PhpMiniSearch\Http;

/**
 * One parsed HTTP request. Header names are lowercase; a header sent more
 * than once is joined with ", ", as RFC 9110 allows for list-valued fields.
 */
final readonly class Request
{
    /**
     * @param array<string, string> $headers lowercase name → value
     */
    public function __construct(
        public string $method,
        public string $target,
        public string $version,
        public array $headers,
        public string $body,
    ) {
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function path(): string
    {
        $path = strstr($this->target, '?', true);

        return $path === false ? $this->target : $path;
    }

    /**
     * @return array<array-key, mixed>
     */
    public function query(): array
    {
        $query = strstr($this->target, '?');
        if ($query === false) {
            return [];
        }
        parse_str(substr($query, 1), $values);

        return $values;
    }

    /**
     * Whether the client allows the connection to stay open after the
     * response: the default in HTTP/1.1, and opt-in in HTTP/1.0.
     */
    public function keepAlive(): bool
    {
        $connection = strtolower($this->header('connection') ?? '');
        $tokens = array_map('trim', explode(',', $connection));

        if ($this->version === '1.0') {
            return in_array('keep-alive', $tokens, true);
        }

        return !in_array('close', $tokens, true);
    }
}
