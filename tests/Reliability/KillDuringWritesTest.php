<?php

declare(strict_types=1);

namespace PhpMiniSearch\Tests\Reliability;

use PhpMiniSearch\Tests\Support\ServiceProcess;
use PhpMiniSearch\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The promise of docs/FAILURE-MODEL.md, on the real process: a write the
 * service acknowledged survives a crash.
 *
 * Four connections keep PUTs in flight against a running service, each one
 * with a newer source version than the last, and SIGKILL lands at a random
 * moment. After a restart, every write that got `200 applied:true` must be
 * there — at its version or a newer one, since a write can be applied and
 * fsynced with its response lost in the crash — and nothing may be there
 * that was never sent.
 *
 * Limit, stated in FAILURE-MODEL.md: SIGKILL is not a power cut. The page
 * cache still reaches the disk after a killed process, so this cannot prove
 * that fsync is called; LogTest and the full-disk test cover the log's side.
 */
final class KillDuringWritesTest extends TestCase
{
    private const int CONNECTIONS = 4;

    private const int IDS = 30;

    private int $acknowledgements = 0;

    /**
     * @return iterable<string, array{int}>
     */
    public static function runs(): iterable
    {
        foreach ([1, 2, 3, 4, 5] as $run) {
            yield "run {$run}" => [$run];
        }
    }

    #[DataProvider('runs')]
    public function testNoAcknowledgedWriteIsLostToAKill(int $run): void
    {
        $directory = new TemporaryDirectory();
        $data = $directory->path('data');
        mt_srand($run);

        try {
            $service = new ServiceProcess($data, ['MINI_SEARCH_LOG_LEVEL' => 'error']);
            [$sent, $acknowledged] = $this->writeUntilKilled($service, mt_rand(150, 700) / 1000);
            $this->assertSame(128 + SIGKILL, $service->wait());

            $this->assertGreaterThan(20, $this->acknowledgements, 'too few writes were acknowledged to mean anything');

            $restarted = new ServiceProcess($data, ['MINI_SEARCH_LOG_LEVEL' => 'error']);
            try {
                foreach ($acknowledged as $id => $version) {
                    $document = $restarted->request('GET', "/documents/{$id}")['body'];
                    $this->assertSame('live', $document['state'] ?? null, "run {$run}: acknowledged {$id} v{$version} is missing");
                    $this->assertGreaterThanOrEqual($version, $document['source_version'] ?? 0, "run {$run}: {$id} went back in time");
                    $this->assertLessThanOrEqual($sent[$id], $document['source_version'] ?? 0, "run {$run}: {$id} has a version never sent");
                }

                $stats = $restarted->request('GET', '/stats')['body'];
                $this->assertLessThanOrEqual(count($sent), $stats['documents'] ?? PHP_INT_MAX, "run {$run}: documents that were never sent");
                $this->assertSame(404, $restarted->request('GET', '/documents/never-sent')['status']);
            } finally {
                $restarted->signal(SIGTERM);
                $restarted->wait();
            }
        } finally {
            $directory->remove();
        }
    }

    /**
     * Keeps one PUT in flight per connection until $seconds have passed,
     * then SIGKILLs the service and reads whatever responses made it out.
     *
     * @return array{array<string, int>, array<string, int>} [id → highest version sent, id → highest version acknowledged]
     */
    private function writeUntilKilled(ServiceProcess $service, float $seconds): array
    {
        $sent = [];
        $acknowledged = [];
        /** @var array<int, array{string, int}> $inFlight */
        $inFlight = [];
        /** @var array<int, string> $buffers */
        $buffers = [];
        /** @var array<int, resource> $sockets */
        $sockets = [];

        $send = function (int $slot) use ($service, &$sent, &$inFlight, &$sockets): void {
            $id = 'SKU-' . mt_rand(1, self::IDS);
            $version = ($sent[$id] ?? 0) + 1;
            $sent[$id] = $version;
            $inFlight[$slot] = [$id, $version];
            fwrite($sockets[$slot], $service->raw('PUT', "/documents/{$id}", [
                'source_version' => $version,
                'title' => "Кабель {$id} v{$version}",
                'body' => str_repeat('быстрая зарядка ', mt_rand(1, 50)),
            ], keepAlive: true));
        };

        for ($slot = 0; $slot < self::CONNECTIONS; $slot++) {
            $sockets[$slot] = $service->connect();
            stream_set_blocking($sockets[$slot], false);
            $buffers[$slot] = '';
            $send($slot);
        }

        $killAt = microtime(true) + $seconds;
        $killed = false;
        while ($sockets !== []) {
            if (!$killed && microtime(true) >= $killAt) {
                $service->signal(SIGKILL);
                $killed = true;
            }

            $read = array_values($sockets);
            $write = $except = null;
            if (stream_select($read, $write, $except, 0, 50_000) === false) {
                continue;
            }

            foreach ($read as $socket) {
                $slot = array_search($socket, $sockets, true);
                if (!is_int($slot)) {
                    continue;
                }
                $chunk = fread($socket, 65_536);
                if ($chunk === '' || $chunk === false) {
                    if (feof($socket)) {
                        fclose($socket);
                        unset($sockets[$slot]);
                    }
                    continue;
                }
                $buffers[$slot] .= $chunk;

                $end = strpos($buffers[$slot], "\r\n\r\n");
                if ($end === false || preg_match('/content-length: (\d+)/i', substr($buffers[$slot], 0, $end), $length) !== 1) {
                    continue;
                }
                $total = $end + 4 + (int) $length[1];
                if (strlen($buffers[$slot]) < $total) {
                    continue;
                }

                $body = json_decode(substr($buffers[$slot], $end + 4, (int) $length[1]), true);
                $buffers[$slot] = (string) substr($buffers[$slot], $total);
                if (is_array($body) && ($body['applied'] ?? false) === true) {
                    [$id, $version] = $inFlight[$slot];
                    $acknowledged[$id] = max($acknowledged[$id] ?? 0, $version);
                    $this->acknowledgements++;
                }
                if (!$killed) {
                    $send($slot);
                }
            }
        }

        return [$sent, $acknowledged];
    }
}
