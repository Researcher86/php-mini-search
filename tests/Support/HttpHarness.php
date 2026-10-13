<?php

declare(strict_types=1);

namespace PhpMiniSearch\Tests\Support;

use PhpMiniSearch\Http\RequestHandler;
use PhpMiniSearch\Http\Server;
use PhpMiniSearch\Http\ServerConfig;
use RuntimeException;

/**
 * Runs a real Server on a real port inside the test process, with a clock the
 * test controls.
 *
 * The client sockets are non-blocking and the harness turns the server's
 * loop itself while it waits, so client and server share one process
 * without deadlocking, and a timeout test moves the clock forward instead of
 * sleeping.
 */
final class HttpHarness
{
    public readonly Server $server;

    public float $now = 1_000.0;

    private readonly string $address;

    /** @var array<int, string> bytes read past the end of a response, per socket */
    private array $leftover = [];

    public function __construct(RequestHandler $handler, ?ServerConfig $config = null)
    {
        $config ??= new ServerConfig(port: 0);
        $this->server = new Server($handler, new ServerConfig(
            host: '127.0.0.1',
            port: 0,
            maxConnections: $config->maxConnections,
            maxHeadBytes: $config->maxHeadBytes,
            maxBodyBytes: $config->maxBodyBytes,
            headTimeout: $config->headTimeout,
            bodyTimeout: $config->bodyTimeout,
            idleTimeout: $config->idleTimeout,
            writeTimeout: $config->writeTimeout,
        ), fn (): float => $this->now);
        $this->address = $this->server->listen();
    }

    /**
     * @return resource
     */
    public function connect()
    {
        $socket = stream_socket_client("tcp://{$this->address}", $code, $message, 1.0);
        if ($socket === false) {
            throw new RuntimeException("connect: {$message}");
        }
        stream_set_blocking($socket, false);
        $this->tick(3);

        return $socket;
    }

    /**
     * Writes every byte, turning the server while the socket is full.
     *
     * @param resource $socket
     */
    public function send($socket, string $bytes): void
    {
        for ($tries = 0; $bytes !== '' && $tries < 100_000; $tries++) {
            $written = @fwrite($socket, $bytes);
            if ($written === false) {
                return;
            }
            $bytes = (string) substr($bytes, $written);
            $this->tick();
        }
    }

    /**
     * Reads one response; null if the server closed the connection first.
     *
     * @param resource $socket
     * @param bool $toHead the response answers a HEAD: its Content-Length describes a body that is not sent
     * @return array{status: int, headers: array<string, string>, body: string}|null
     */
    public function receive($socket, bool $toHead = false, int $maxTicks = 2_000): ?array
    {
        $buffer = $this->leftover[(int) $socket] ?? '';
        $closed = false;
        for ($i = 0; $i < $maxTicks; $i++) {
            $end = strpos($buffer, "\r\n\r\n");
            if ($end !== false) {
                $response = $this->parse(substr($buffer, 0, $end), substr($buffer, $end + 4), $toHead);
                if ($response !== null) {
                    $consumed = $end + 4 + strlen($response['body']);
                    $this->leftover[(int) $socket] = (string) substr($buffer, $consumed);

                    return $response;
                }
            }
            if ($closed) {
                return null;
            }

            $this->tick();
            $chunk = @fread($socket, 1 << 20);
            if (is_string($chunk)) {
                $buffer .= $chunk;
            }
            $closed = feof($socket);
        }

        throw new RuntimeException('No response within ' . $maxTicks . ' ticks; got ' . strlen($buffer) . ' bytes.');
    }

    /**
     * Whether the server has closed this connection.
     *
     * @param resource $socket
     */
    public function isClosed($socket): bool
    {
        for ($i = 0; $i < 50; $i++) {
            $this->tick();
            $chunk = @fread($socket, 65_536);
            if (($chunk === '' || $chunk === false) && feof($socket)) {
                return true;
            }
        }

        return false;
    }

    public function tick(int $times = 1): void
    {
        for ($i = 0; $i < $times; $i++) {
            $this->server->tick(0.002);
        }
    }

    public function close(): void
    {
        $this->server->close();
    }

    /**
     * @return array{status: int, headers: array<string, string>, body: string}|null null while the body is incomplete
     */
    private function parse(string $head, string $rest, bool $toHead): ?array
    {
        $lines = explode("\r\n", $head);
        $status = (int) explode(' ', array_shift($lines))[1];
        $headers = [];
        foreach ($lines as $line) {
            [$name, $value] = explode(':', $line, 2);
            $headers[strtolower($name)] = trim($value);
        }
        if ($status === 100) {
            return ['status' => 100, 'headers' => $headers, 'body' => ''];
        }

        $length = $toHead ? 0 : (int) ($headers['content-length'] ?? 0);

        return strlen($rest) >= $length ? ['status' => $status, 'headers' => $headers, 'body' => substr($rest, 0, $length)] : null;
    }
}
