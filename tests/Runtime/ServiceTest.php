<?php

declare(strict_types=1);

namespace PhpMiniSearch\Tests\Runtime;

use PhpMiniSearch\Tests\Support\ServiceProcess;
use PhpMiniSearch\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\TestCase;

/**
 * The service as an operator sees it: a real process, started by
 * bin/mini-search serve, spoken to over TCP, stopped with signals.
 */
final class ServiceTest extends TestCase
{
    private TemporaryDirectory $directory;

    /** @var list<ServiceProcess> */
    private array $processes = [];

    protected function setUp(): void
    {
        $this->directory = new TemporaryDirectory();
    }

    protected function tearDown(): void
    {
        foreach ($this->processes as $process) {
            $process->stop();
        }
        $this->directory->remove();
    }

    public function testAFreshServiceAsksForAReindexThenServesAndStopsCleanly(): void
    {
        $service = $this->start();

        $this->assertSame(503, $service->request('GET', '/health/ready', withToken: false)['status']);
        $this->assertSame(1, $service->request('POST', '/reindex')['body']['generation'] ?? null);
        $this->assertSame(200, $service->request('PUT', '/documents/SKU-1', ['source_version' => 1, 'title' => 'Кабель USB-C', 'body' => '', 'generation' => 1])['status']);
        $this->assertSame(200, $service->request('POST', '/reindex/1/complete')['status']);
        $this->assertSame(200, $service->request('GET', '/health/ready', withToken: false)['status']);
        $this->assertSame(1, $service->request('POST', '/search', ['query' => 'кабели'])['body']['total'] ?? null);

        $service->signal(SIGTERM);

        $this->assertSame(0, $service->wait());
        $events = array_column($service->logLines(), 'event');
        $this->assertContains('replayed', $events);
        $this->assertSame('stopped', end($events));
    }

    public function testARestartReplaysTheLog(): void
    {
        $first = $this->start();
        $first->request('POST', '/reindex');
        $first->request('PUT', '/documents/SKU-1', ['source_version' => 1, 'title' => 'Кабель', 'body' => '']);
        $first->request('POST', '/reindex/1/complete');
        $first->signal(SIGTERM);
        $this->assertSame(0, $first->wait());

        $second = $this->start();

        $this->assertSame(200, $second->request('GET', '/health/ready', withToken: false)['status']);
        $this->assertSame(['SKU-1'], array_column((array) ($second->request('POST', '/search', ['query' => 'кабель'])['body']['results'] ?? []), 'id'));
    }

    public function testADrainFinishesTheRequestInFlightAndClosesTheConnection(): void
    {
        $service = $this->start();
        $socket = $service->connect();
        $raw = $service->raw('PUT', '/documents/a', ['source_version' => 1, 'title' => 'x', 'body' => 'y'], keepAlive: true);
        fwrite($socket, substr($raw, 0, -5));
        usleep(100_000);

        $service->signal(SIGTERM);
        usleep(200_000);
        fwrite($socket, substr($raw, -5));
        $response = $service->readResponse($socket);

        $this->assertSame(200, $response['status']);
        $this->assertSame('close', $response['headers']['connection'] ?? null);
        $this->assertSame(0, $service->wait());
    }

    public function testAnIdleKeepAliveConnectionDoesNotHoldUpTheDrain(): void
    {
        $service = $this->start();
        $socket = $service->connect();
        fwrite($socket, $service->raw('GET', '/health/live', withToken: false, keepAlive: true));
        $this->assertSame(200, $service->readResponse($socket)['status']);

        $started = microtime(true);
        $service->signal(SIGTERM);

        $this->assertSame(0, $service->wait());
        $this->assertLessThan(1.0, microtime(true) - $started);
    }

    public function testADrainThatRunsOutOfTimeExitsWithAnError(): void
    {
        $service = $this->start(['MINI_SEARCH_DRAIN_SECONDS' => '0.5']);
        $socket = $service->connect();
        fwrite($socket, "PUT /documents/a HTTP/1.1\r\nHost: x\r\nContent-Length: 100\r\n\r\nnever finished");
        usleep(100_000);

        $service->signal(SIGTERM);

        $this->assertSame(1, $service->wait());
        $this->assertContains('drain_deadline_passed', array_column($service->logLines(), 'event'));
    }

    public function testASecondSignalStopsAtOnce(): void
    {
        $service = $this->start(['MINI_SEARCH_DRAIN_SECONDS' => '30']);
        $socket = $service->connect();
        fwrite($socket, "PUT /documents/a HTTP/1.1\r\nHost: x\r\nContent-Length: 100\r\n\r\nnever finished");
        usleep(100_000);

        $service->signal(SIGTERM);
        usleep(200_000);
        $started = microtime(true);
        $service->signal(SIGTERM);

        $this->assertSame(1, $service->wait());
        $this->assertLessThan(1.0, microtime(true) - $started);
    }

    public function testWithoutATokenTheServiceRefusesToStart(): void
    {
        $output = [];
        exec(
            'env -i PATH="$PATH" ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../../bin/mini-search') . ' serve 2>&1',
            $output,
            $exitCode,
        );

        $this->assertSame(2, $exitCode);
        $this->assertStringContainsString('token of at least 32 bytes', implode("\n", $output));
    }

    /**
     * @param array<string, string> $environment
     */
    private function start(array $environment = []): ServiceProcess
    {
        return $this->processes[] = new ServiceProcess($this->directory->path('data'), $environment);
    }
}
