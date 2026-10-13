<?php

declare(strict_types=1);

namespace PhpMiniSearch\Http;

/**
 * Turns the bytes of one connection into requests, however TCP slices them.
 *
 * TCP is a byte stream: one read may hold half a request line, or the end of
 * one request and the start of the next. So the parser never assumes a read
 * is a request. feed() appends bytes; next() returns a complete Request, an
 * HttpError, or null for "not enough bytes yet". Bytes after a request stay
 * in the buffer for the next call — that is all keep-alive and pipelining
 * need from a parser.
 *
 * The supported subset is deliberately narrow, and everything outside it is
 * refused, never guessed at (docs/ARCHITECTURE.md, HTTP subset):
 *
 *  - lines end in CRLF; a bare CR or LF, a NUL, or a folded header line is 400;
 *  - HTTP/1.0 and HTTP/1.1 only, origin-form targets only, Host required in 1.1;
 *  - the body is framed by Content-Length only. Any Transfer-Encoding is
 *    501; a repeated or non-numeric Content-Length is 400; POST or PUT
 *    without one is 411 — ambiguous framing is how request smuggling starts;
 *  - a head over the limit is 431, a body over the limit 413 — decided from
 *    the headers, before the body is read.
 *
 * An error is final: the parser stops, because after a framing error nobody
 * knows where the next request would begin.
 */
final class RequestParser
{
    private const string TOKEN = '/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/';

    private string $buffer = '';

    /** @var array{method: string, target: string, version: string, headers: array<string, string>, length: int}|null */
    private ?array $head = null;

    private bool $continueWanted = false;

    private ?HttpError $error = null;

    public function __construct(
        private readonly int $maxHeadBytes = 8_192,
        private readonly int $maxBodyBytes = 262_144,
    ) {
    }

    public function feed(string $bytes): void
    {
        $this->buffer .= $bytes;
    }

    /**
     * @phpstan-impure
     */
    public function next(): Request|HttpError|null
    {
        if ($this->error !== null) {
            return $this->error;
        }

        if ($this->head === null) {
            $this->buffer = $this->skipEmptyLines($this->buffer);

            $end = strpos($this->buffer, "\r\n\r\n");
            if ($end === false) {
                return strlen($this->buffer) > $this->maxHeadBytes
                    ? $this->fail(431, 'headers_too_large', 'The request line and headers exceed ' . $this->maxHeadBytes . ' bytes.')
                    : null;
            }
            if ($end + 4 > $this->maxHeadBytes) {
                return $this->fail(431, 'headers_too_large', 'The request line and headers exceed ' . $this->maxHeadBytes . ' bytes.');
            }

            $head = $this->parseHead(substr($this->buffer, 0, $end));
            if ($head instanceof HttpError) {
                return $this->fail($head->status, $head->code, $head->message);
            }

            $this->buffer = substr($this->buffer, $end + 4);
            $this->head = $head;
            $this->continueWanted = $head['version'] === '1.1'
                && $head['length'] > 0
                && strtolower($head['headers']['expect'] ?? '') === '100-continue';
        }

        if (strlen($this->buffer) < $this->head['length']) {
            return null;
        }

        $request = new Request(
            $this->head['method'],
            $this->head['target'],
            $this->head['version'],
            $this->head['headers'],
            substr($this->buffer, 0, $this->head['length']),
        );
        $this->buffer = (string) substr($this->buffer, $this->head['length']);
        $this->head = null;
        $this->continueWanted = false;

        return $request;
    }

    /**
     * True once, right after the head of a request that sent
     * `Expect: 100-continue` has been accepted and before its body has
     * arrived: the server should answer `100 Continue` now.
     *
     * @phpstan-impure
     */
    public function takeContinue(): bool
    {
        $wanted = $this->continueWanted && $this->head !== null && strlen($this->buffer) < $this->head['length'];
        $this->continueWanted = false;

        return $wanted;
    }

    /** Some bytes of a request head have arrived, but not all of it. */
    public function inHead(): bool
    {
        return $this->head === null && $this->error === null && $this->skipEmptyLines($this->buffer) !== '';
    }

    /** The head is complete and the body is still arriving. */
    public function inBody(): bool
    {
        return $this->head !== null;
    }

    /** A complete request is waiting in the buffer. */
    public function hasBufferedRequest(): bool
    {
        if ($this->error !== null) {
            return true;
        }
        if ($this->head !== null) {
            return strlen($this->buffer) >= $this->head['length'];
        }

        return str_contains($this->buffer, "\r\n\r\n");
    }

    /**
     * @return array{method: string, target: string, version: string, headers: array<string, string>, length: int}|HttpError
     */
    private function parseHead(string $head): array|HttpError
    {
        if (preg_match('/[\0]|\r(?!\n)|(?<!\r)\n/', $head) === 1) {
            return $this->malformed('A line does not end in CRLF, or contains a NUL byte.');
        }

        $lines = explode("\r\n", $head);
        $parts = explode(' ', array_shift($lines));
        if (count($parts) !== 3) {
            return $this->malformed('The request line is not "METHOD target HTTP/x.y".');
        }
        [$method, $target, $protocol] = $parts;

        if (preg_match(self::TOKEN, $method) !== 1) {
            return $this->malformed('The method is not a token.');
        }
        if (!str_starts_with($target, '/')) {
            return $this->malformed('Only origin-form targets ("/path") are supported.');
        }
        if ($protocol !== 'HTTP/1.1' && $protocol !== 'HTTP/1.0') {
            return $this->malformed('Only HTTP/1.0 and HTTP/1.1 are supported.');
        }

        $headers = [];
        $lengths = [];
        foreach ($lines as $line) {
            if ($line === '' || $line[0] === ' ' || $line[0] === "\t") {
                return $this->malformed('Folded header lines are not supported.');
            }
            $colon = strpos($line, ':');
            if ($colon === false) {
                return $this->malformed('A header line has no colon.');
            }
            $name = substr($line, 0, $colon);
            if (preg_match(self::TOKEN, $name) !== 1) {
                return $this->malformed('A header name is not a token, or has whitespace before the colon.');
            }

            $name = strtolower($name);
            $value = trim(substr($line, $colon + 1), " \t");
            if ($name === 'content-length') {
                $lengths[] = $value;
            }
            $headers[$name] = isset($headers[$name]) ? $headers[$name] . ', ' . $value : $value;
        }

        $version = substr($protocol, 5);
        if ($version === '1.1' && !isset($headers['host'])) {
            return $this->malformed('HTTP/1.1 requires a Host header.');
        }
        if (isset($headers['transfer-encoding'])) {
            return new HttpError(501, 'unsupported_transfer_encoding', 'Transfer-Encoding is not supported; send Content-Length.');
        }
        if (count($lengths) > 1) {
            return $this->malformed('Content-Length is sent more than once.');
        }

        $length = 0;
        if ($lengths !== []) {
            if (preg_match('/^[0-9]{1,15}$/', $lengths[0]) !== 1) {
                return $this->malformed('Content-Length is not a plain decimal number.');
            }
            $length = (int) $lengths[0];
        } elseif ($method === 'POST' || $method === 'PUT' || $method === 'PATCH') {
            return new HttpError(411, 'length_required', 'A request with a body needs Content-Length.');
        }

        if ($length > $this->maxBodyBytes) {
            return new HttpError(413, 'payload_too_large', 'The body exceeds ' . $this->maxBodyBytes . ' bytes.');
        }

        return ['method' => $method, 'target' => $target, 'version' => $version, 'headers' => $headers, 'length' => $length];
    }

    private function malformed(string $message): HttpError
    {
        return new HttpError(400, 'malformed_request', $message);
    }

    private function fail(int $status, string $code, string $message): HttpError
    {
        $this->buffer = '';
        $this->head = null;

        return $this->error = new HttpError($status, $code, $message);
    }

    /**
     * RFC 9112 asks servers to ignore empty lines before a request line;
     * some clients send a stray CRLF after a body.
     */
    private function skipEmptyLines(string $buffer): string
    {
        while (str_starts_with($buffer, "\r\n")) {
            $buffer = substr($buffer, 2);
        }

        return $buffer;
    }
}
