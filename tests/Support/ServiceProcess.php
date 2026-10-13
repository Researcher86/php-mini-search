<?php

declare(strict_types=1);

namespace PhpMiniSearch\Tests\Support;

use RuntimeException;

/**
 * `bin/mini-search serve` as a real child process: started with its own
 * environment, found through the "listening" line it logs, talked to over
 * real sockets, and stopped with real signals.
 */
final class ServiceProcess
{
    public const string TOKEN = '0123456789abcdef0123456789abcdef';

    /** @var resource */
    private $process;

    /** @var resource */
    private $stderr;

    private string $log = '';

    public readonly string $address;

    private ?int $exitCode = null;

    /**
     * @param array<string, string> $environment on top of the defaults: the token, port 0, and $dataDirectory
     */
    public function __construct(string $dataDirectory, array $environment = [], private readonly float $startTimeout = 10.0)
    {
        $environment += [
            'MINI_SEARCH_TOKEN' => self::TOKEN,
            'MINI_SEARCH_PORT' => '0',
            'MINI_SEARCH_DATA_DIR' => $dataDirectory,
            'PATH' => (string) getenv('PATH'),
        ];

        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/../../bin/mini-search', 'serve'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $environment,
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Could not start bin/mini-search serve.');
        }
        $this->process = $process;
        $this->stderr = $pipes[2];
        stream_set_blocking($this->stderr, false);

        $this->address = $this->waitForAddress();
    }

    /**
     * One request on its own connection.
     *
     * @param array<mixed>|null $json
     * @return array{status: int, headers: array<string, string>, body: array<mixed>}
     */
    public function request(string $method, string $target, ?array $json = null, bool $withToken = true): array
    {
        $socket = $this->connect();
        fwrite($socket, $this->raw($method, $target, $json, $withToken));

        return $this->readResponse($socket);
    }

    /**
     * @return resource
     */
    public function connect()
    {
        $socket = stream_socket_client("tcp://{$this->address}", $code, $message, 2.0);
        if ($socket === false) {
            throw new RuntimeException("connect: {$message}");
        }
        stream_set_timeout($socket, 5);

        return $socket;
    }

    /**
     * @param array<mixed>|null $json
     */
    public function raw(string $method, string $target, ?array $json = null, bool $withToken = true, bool $keepAlive = false): string
    {
        $body = $json === null ? '' : (string) json_encode($json);
        $headers = "Host: x\r\n" . ($keepAlive ? '' : "Connection: close\r\n");
        if ($withToken) {
            $headers .= 'Authorization: Bearer ' . self::TOKEN . "\r\n";
        }
        if ($json !== null) {
            $headers .= "Content-Type: application/json\r\nContent-Length: " . strlen($body) . "\r\n";
        }

        return "{$method} {$target} HTTP/1.1\r\n{$headers}\r\n{$body}";
    }

    /**
     * @param resource $socket
     * @return array{status: int, headers: array<string, string>, body: array<mixed>}
     */
    public function readResponse($socket): array
    {
        $raw = '';
        while (!str_contains($raw, "\r\n\r\n") && !feof($socket)) {
            $raw .= (string) fread($socket, 8_192);
        }
        [$head, $body] = explode("\r\n\r\n", $raw, 2) + [1 => ''];

        $lines = explode("\r\n", $head);
        $status = (int) (explode(' ', (string) array_shift($lines))[1] ?? 0);
        $headers = [];
        foreach ($lines as $line) {
            [$name, $value] = explode(':', $line, 2) + [1 => ''];
            $headers[strtolower($name)] = trim($value);
        }
        $length = (int) ($headers['content-length'] ?? 0);
        while (strlen($body) < $length && !feof($socket)) {
            $body .= (string) fread($socket, 8_192);
        }

        $decoded = json_decode($body, true);

        return ['status' => $status, 'headers' => $headers, 'body' => is_array($decoded) ? $decoded : []];
    }

    public function pid(): int
    {
        return proc_get_status($this->process)['pid'];
    }

    public function signal(int $signal): void
    {
        proc_terminate($this->process, $signal);
    }

    /**
     * Waits for the process to exit and returns its exit code; a process
     * ended by a signal reports 128 + the signal, as a shell does.
     */
    public function wait(float $timeout = 10.0): int
    {
        $deadline = microtime(true) + $timeout;
        while ($this->exitCode === null) {
            $this->drainStderr();
            $status = proc_get_status($this->process);
            if (!$status['running']) {
                $this->exitCode = $status['signaled'] ? 128 + $status['termsig'] : $status['exitcode'];
                break;
            }
            if (microtime(true) > $deadline) {
                proc_terminate($this->process, SIGKILL);
                throw new RuntimeException("The service did not exit within {$timeout} s. Log:\n{$this->log}");
            }
            usleep(10_000);
        }
        $this->drainStderr();

        return (int) $this->exitCode;
    }

    /**
     * @return list<array<string, mixed>> every JSON line the service logged so far
     */
    public function logLines(): array
    {
        $this->drainStderr();
        $lines = [];
        foreach (explode("\n", trim($this->log)) as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                /** @var array<string, mixed> $decoded */
                $lines[] = $decoded;
            }
        }

        return $lines;
    }

    public function stop(): void
    {
        if ($this->exitCode === null && proc_get_status($this->process)['running']) {
            $this->signal(SIGKILL);
            $this->wait();
        }
    }

    private function waitForAddress(): string
    {
        $deadline = microtime(true) + $this->startTimeout;
        while (microtime(true) < $deadline) {
            foreach ($this->logLines() as $line) {
                if (($line['event'] ?? null) === 'listening' && is_string($line['address'] ?? null)) {
                    return $line['address'];
                }
            }
            if (!proc_get_status($this->process)['running']) {
                throw new RuntimeException("The service exited before listening. Log:\n{$this->log}");
            }
            usleep(10_000);
        }

        throw new RuntimeException("The service did not start listening. Log:\n{$this->log}");
    }

    private function drainStderr(): void
    {
        $chunk = stream_get_contents($this->stderr);
        if (is_string($chunk)) {
            $this->log .= $chunk;
        }
    }
}
