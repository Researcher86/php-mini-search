<?php

declare(strict_types=1);

namespace PhpMiniSearch\Http;

/**
 * Every limit the server enforces. With one process and one loop, any
 * resource left unbounded is a denial of service for every client at once,
 * so each value here answers "what stops one client from holding this
 * forever?" (docs/ARCHITECTURE.md, HTTP subset).
 */
final readonly class ServerConfig
{
    public function __construct(
        public string $host = '127.0.0.1',
        public int $port = 8080,
        /** Beyond this, accept() is not called and new clients wait in the kernel's backlog. */
        public int $maxConnections = 256,
        /** Request line plus headers; more is 431. */
        public int $maxHeadBytes = 8_192,
        /** More is 413, decided from Content-Length before the body is read. */
        public int $maxBodyBytes = 262_144,
        /** From the first byte of a request to the end of its headers (Slowloris). */
        public float $headTimeout = 10.0,
        /** From the end of the headers to the end of the body. */
        public float $bodyTimeout = 30.0,
        /** An open keep-alive connection with nothing in flight. */
        public float $idleTimeout = 30.0,
        /** From the first byte of a response to the last; a client that stops reading is dropped. */
        public float $writeTimeout = 30.0,
        public int $readChunkBytes = 65_536,
    ) {
    }
}
