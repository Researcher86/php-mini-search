<?php

declare(strict_types=1);

namespace PhpMiniSearch\Http;

use Closure;
use RuntimeException;
use Throwable;

/**
 * One process, one stream_select() loop, many connections
 * (docs/DECISIONS.md, "One process, one loop").
 *
 * Client I/O is multiplexed: reads and writes are non-blocking, the parser is
 * incremental, and a slow client only ever costs its own deadlines. Request
 * handling is not: the handler runs synchronously on the loop, so every
 * request is serialized with every other one — which is exactly what lets the
 * corpus behind the handler stay consistent without locks.
 *
 * Each tick:
 *
 *  1. drop connections past a deadline (head, body, idle keep-alive, write);
 *  2. select on the listener (only below the connection cap), on every
 *     connection that wants to read, and on every connection with bytes to
 *     send, waiting no longer than the nearest deadline;
 *  3. accept, read, write, and run each connection's next request.
 *
 * Pipelining is sequential: a connection's next request is parsed only after
 * the previous response has been written in full. That keeps responses in
 * order and is the backpressure — a client that pipelines without reading
 * fills its own socket buffer, not the server's memory.
 *
 * The clock is injectable so that tests can move time instead of sleeping.
 */
final class Server
{
    /** @var array<int, Connection> keyed by socket id */
    private array $connections = [];

    /** @var resource|null */
    private $listener = null;

    /** @var Closure(): float */
    private readonly Closure $clock;

    private bool $draining = false;

    /**
     * @param (Closure(): float)|null $clock seconds, monotonic; defaults to hrtime()
     */
    public function __construct(
        private readonly RequestHandler $handler,
        private readonly ServerConfig $config = new ServerConfig(),
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? fn (): float => hrtime(true) / 1e9;
    }

    /**
     * Binds the listening socket and returns its address. Port 0 picks a free
     * port, which is how the tests run.
     */
    public function listen(): string
    {
        $address = "tcp://{$this->config->host}:{$this->config->port}";
        $context = stream_context_create(['socket' => ['backlog' => 511, 'so_reuseport' => false]]);
        $listener = @stream_socket_server($address, $errorCode, $errorMessage, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);

        if ($listener === false) {
            throw new RuntimeException("Could not listen on {$address}: {$errorMessage} ({$errorCode}).");
        }

        stream_set_blocking($listener, false);
        $this->listener = $listener;

        return (string) stream_socket_get_name($listener, false);
    }

    public function tick(float $maxWait = 1.0): void
    {
        $now = ($this->clock)();
        $this->expire($now);

        $read = [];
        $write = [];
        if ($this->listener !== null && count($this->connections) < $this->config->maxConnections) {
            $read[] = $this->listener;
        }
        foreach ($this->connections as $connection) {
            if ($connection->wantsRead()) {
                $read[] = $connection->socket;
            }
            if ($connection->output !== '') {
                $write[] = $connection->socket;
            }
        }

        if ($read === [] && $write === []) {
            usleep((int) (min($maxWait, $this->untilNextDeadline($now)) * 1e6));

            return;
        }

        $wait = max(0.0, min($maxWait, $this->untilNextDeadline($now)));
        $except = null;
        // A signal interrupts select() and makes it return false; that is not
        // an error, the next tick simply looks again.
        if (@stream_select($read, $write, $except, (int) $wait, (int) (fmod($wait, 1.0) * 1e6)) === false) {
            return;
        }

        $now = ($this->clock)();
        foreach ($read as $socket) {
            if ($socket === $this->listener) {
                $this->accept($now);
            } elseif (isset($this->connections[(int) $socket])) {
                $this->read($this->connections[(int) $socket], $now);
            }
        }
        foreach ($write as $socket) {
            if (isset($this->connections[(int) $socket])) {
                $this->write($this->connections[(int) $socket], $now);
            }
        }
    }

    public function connectionCount(): int
    {
        return count($this->connections);
    }

    /**
     * Starts a graceful shutdown: stop accepting, close every connection
     * with nothing in flight, and let the ones in the middle of a request
     * finish it — their response carries `Connection: close`. The caller
     * keeps calling tick() until connectionCount() is 0 or its deadline
     * passes.
     */
    public function drain(): void
    {
        $this->draining = true;

        if ($this->listener !== null) {
            fclose($this->listener);
            $this->listener = null;
        }

        foreach ($this->connections as $connection) {
            if ($connection->isIdle() && !$connection->parser->hasBufferedRequest()) {
                $this->drop($connection);
            }
        }
    }

    public function close(): void
    {
        foreach ($this->connections as $connection) {
            $this->drop($connection);
        }
        if ($this->listener !== null) {
            fclose($this->listener);
            $this->listener = null;
        }
    }

    private function accept(float $now): void
    {
        while ($this->listener !== null && count($this->connections) < $this->config->maxConnections) {
            $socket = @stream_socket_accept($this->listener, 0);
            if ($socket === false) {
                return;
            }

            stream_set_blocking($socket, false);
            $parser = new RequestParser($this->config->maxHeadBytes, $this->config->maxBodyBytes);
            $this->connections[(int) $socket] = new Connection($socket, $parser, $now);
        }
    }

    private function read(Connection $connection, float $now): void
    {
        $bytes = @fread($connection->socket, max(1, $this->config->readChunkBytes));

        if ($bytes === false || ($bytes === '' && feof($connection->socket))) {
            $this->drop($connection);

            return;
        }

        $connection->parser->feed($bytes);
        $this->process($connection, $now);
    }

    private function write(Connection $connection, float $now): void
    {
        $written = @fwrite($connection->socket, $connection->output);

        if ($written === false || ($written === 0 && feof($connection->socket))) {
            $this->drop($connection);

            return;
        }

        $connection->output = (string) substr($connection->output, $written);
        if ($connection->output !== '') {
            return;
        }

        $connection->writeStartedAt = null;
        if ($connection->closeAfterWrite) {
            $this->drop($connection);

            return;
        }

        $connection->idleSince = $now;
        $this->process($connection, $now);
        if ($this->draining && $connection->isIdle() && isset($this->connections[(int) $connection->socket])) {
            $this->drop($connection);
        }
    }

    /**
     * Runs the next buffered request, if there is one and the previous
     * response has gone out.
     */
    private function process(Connection $connection, float $now): void
    {
        $parser = $connection->parser;

        if ($connection->output === '' && !$connection->closeAfterWrite) {
            if ($parser->takeContinue()) {
                $this->send($connection, "HTTP/1.1 100 Continue\r\n\r\n", $now);
            } else {
                $next = $parser->next();

                if ($next instanceof HttpError) {
                    $this->respond($connection, $this->handler->reject($next), false, true, false, $now);
                } elseif ($next instanceof Request) {
                    $connection->headStartedAt = null;
                    $connection->bodyStartedAt = null;
                    $this->respond(
                        $connection,
                        $this->handle($next),
                        $next->keepAlive(),
                        $next->method !== 'HEAD',
                        $next->version === '1.0',
                        $now,
                    );
                } elseif ($parser->takeContinue()) {
                    $this->send($connection, "HTTP/1.1 100 Continue\r\n\r\n", $now);
                }
            }
        }

        if ($parser->inHead() && $connection->headStartedAt === null) {
            $connection->headStartedAt = $now;
        }
        if ($parser->inBody() && $connection->bodyStartedAt === null) {
            $connection->bodyStartedAt = $now;
        }
    }

    private function handle(Request $request): Response
    {
        try {
            return $this->handler->handle($request);
        } catch (Throwable) {
            return $this->handler->reject(new HttpError(500, 'internal_error', 'The request could not be handled.'));
        }
    }

    private function respond(Connection $connection, Response $response, bool $keepAlive, bool $withBody, bool $http10, float $now): void
    {
        $keepAlive = $keepAlive && !$this->draining;
        $connection->closeAfterWrite = !$keepAlive;
        $this->send($connection, $response->serialize($keepAlive, $withBody, $http10), $now);
    }

    private function send(Connection $connection, string $bytes, float $now): void
    {
        $connection->output .= $bytes;
        $connection->writeStartedAt ??= $now;
    }

    private function expire(float $now): void
    {
        foreach ($this->connections as $connection) {
            if ($this->overdue($connection, $now) !== null) {
                $this->drop($connection);
            }
        }
    }

    /** The deadline a connection has already passed, or null. */
    private function overdue(Connection $connection, float $now): ?float
    {
        $deadline = $this->deadline($connection);

        return $deadline !== null && $now >= $deadline ? $deadline : null;
    }

    private function deadline(Connection $connection): ?float
    {
        if ($connection->writeStartedAt !== null) {
            return $connection->writeStartedAt + $this->config->writeTimeout;
        }
        if ($connection->parser->inBody() && $connection->bodyStartedAt !== null) {
            return $connection->bodyStartedAt + $this->config->bodyTimeout;
        }
        if ($connection->parser->inHead() && $connection->headStartedAt !== null) {
            return $connection->headStartedAt + $this->config->headTimeout;
        }
        if ($connection->isIdle()) {
            return $connection->idleSince + $this->config->idleTimeout;
        }

        return null;
    }

    private function untilNextDeadline(float $now): float
    {
        $nearest = PHP_FLOAT_MAX;
        foreach ($this->connections as $connection) {
            $deadline = $this->deadline($connection);
            if ($deadline !== null) {
                $nearest = min($nearest, $deadline - $now);
            }
        }

        return max(0.0, $nearest);
    }

    private function drop(Connection $connection): void
    {
        unset($this->connections[(int) $connection->socket]);
        @fclose($connection->socket);
    }
}
