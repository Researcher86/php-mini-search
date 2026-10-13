<?php

declare(strict_types=1);

namespace PhpMiniSearch\Tests\Reliability;

use PhpMiniSearch\Tests\Support\ServiceProcess;
use PHPUnit\Framework\TestCase;

/**
 * The log fails on the real process: its filesystem fills up.
 *
 * The data directory lives on /dev/shm — a small tmpfs inside the
 * development container — and a filler file takes all of it but about
 * 150 KB. PUTs then keep coming until a write fails. The expectations are
 * those of docs/FAILURE-MODEL.md:
 *
 *  - that request gets `503 storage_failure`, and the process stops with
 *    exit code 1 instead of carrying on with a log it can no longer trust;
 *  - after the space is freed and the service restarted, every write
 *    acknowledged before the failure is there;
 *  - the failed write, retried as the queue would retry it, is applied.
 *
 * Skipped where /dev/shm is not a small writable filesystem, so it never
 * tries to fill a real disk.
 */
final class FullDiskTest extends TestCase
{
    private string $root;

    private string $filler;

    protected function setUp(): void
    {
        $free = @disk_free_space('/dev/shm');
        if (PHP_OS_FAMILY !== 'Linux' || !is_writable('/dev/shm') || $free === false || $free > 512 * 1024 * 1024) {
            $this->markTestSkipped('Needs a small writable tmpfs at /dev/shm (the development container has one).');
        }

        $this->root = '/dev/shm/mini-search-' . bin2hex(random_bytes(4));
        $this->filler = $this->root . '-filler';
        mkdir($this->root);
    }

    protected function tearDown(): void
    {
        if (isset($this->filler) && is_file($this->filler)) {
            unlink($this->filler);
        }
        if (isset($this->root)) {
            foreach (glob($this->root . '/{,.}[!.]*', GLOB_BRACE) ?: [] as $file) {
                unlink($file);
            }
            @rmdir($this->root);
        }
    }

    public function testAFullDiskStopsTheServiceAndLosesNoAcknowledgedWrite(): void
    {
        $service = new ServiceProcess($this->root, ['MINI_SEARCH_LOG_LEVEL' => 'error']);
        $this->fillAllBut(150 * 1024);

        $acknowledged = [];
        $failed = null;
        for ($version = 1; $version <= 1_000 && $failed === null; $version++) {
            $id = 'SKU-' . ($version % 40);
            $document = ['source_version' => $version, 'title' => "Кабель {$version}", 'body' => str_repeat('зарядка ', 400)];
            $response = $service->request('PUT', "/documents/{$id}", $document);

            if ($response['status'] === 200) {
                $acknowledged[$id] = $version;
            } else {
                $this->assertSame(503, $response['status']);
                $error = $response['body']['error'] ?? null;
                $this->assertIsArray($error);
                $this->assertSame('storage_failure', $error['code'] ?? null);
                $failed = [$id, $document];
            }
        }

        $this->assertNotNull($failed, 'the disk never filled up');
        $this->assertGreaterThan(5, count($acknowledged));
        $this->assertSame(1, $service->wait());

        unlink($this->filler);
        $restarted = new ServiceProcess($this->root, ['MINI_SEARCH_LOG_LEVEL' => 'error']);
        try {
            foreach ($acknowledged as $id => $version) {
                $this->assertSame($version, $restarted->request('GET', "/documents/{$id}")['body']['source_version'] ?? null, "{$id} lost v{$version}");
            }

            [$id, $document] = $failed;
            $retry = $restarted->request('PUT', "/documents/{$id}", $document);
            $this->assertSame(200, $retry['status']);
            $this->assertTrue($retry['body']['applied'] ?? null);
        } finally {
            $restarted->signal(SIGTERM);
            $restarted->wait();
        }
    }

    private function fillAllBut(int $bytes): void
    {
        $free = (int) disk_free_space('/dev/shm');
        $handle = fopen($this->filler, 'wb');
        $this->assertIsResource($handle);
        $chunk = str_repeat("\0", 1 << 20);
        for ($left = $free - $bytes; $left > 0; $left -= strlen($chunk)) {
            if (@fwrite($handle, $left >= strlen($chunk) ? $chunk : substr($chunk, 0, $left)) === false) {
                break;
            }
        }
        fclose($handle);
    }
}
