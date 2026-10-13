<?php

declare(strict_types=1);

namespace PhpMiniSearch\Http;

/**
 * A request the server refuses before any handler sees it: broken framing,
 * an oversized head or body. The code is one of the stable error codes in
 * docs/API.md; the handler turns it into a response body.
 *
 * After every one of these the connection is closed: the server no longer
 * knows where the next request would begin.
 */
final readonly class HttpError
{
    public function __construct(
        public int $status,
        public string $code,
        public string $message,
    ) {
    }
}
