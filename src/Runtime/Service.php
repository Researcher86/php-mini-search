<?php

declare(strict_types=1);

namespace PhpMiniSearch\Runtime;

use PhpMiniSearch\Api\Api;
use PhpMiniSearch\Corpus\Corpus;
use PhpMiniSearch\Http\Server;
use PhpMiniSearch\Storage\StorageFailure;

/**
 * `bin/mini-search serve`: the whole life of the process.
 *
 *  1. replay the log, before the socket opens — a few seconds at the target
 *     size, and no request ever sees a half-built index;
 *  2. listen, and turn the loop until a signal or a storage failure;
 *  3. drain: stop accepting, close idle connections, let requests in flight
 *     finish with `Connection: close`, until none are left or the deadline
 *     passes.
 *
 * Exit codes: 0 after a clean drain (and after a signal during replay —
 * nothing was in flight, and the index is derived anyway); 1 when the drain
 * deadline passed with connections still open, when the log failed, or on a
 * second signal, which exits at once.
 *
 * There is nothing to flush on the way out: every acknowledged write was
 * fsynced before it was acknowledged.
 */
final class Service
{
    private int $signals = 0;

    private bool $serving = false;

    private ?Server $server = null;

    public function __construct(
        private readonly Config $config,
        private readonly Logger $logger,
    ) {
    }

    public function run(): int
    {
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, $this->onSignal(...));
        pcntl_signal(SIGINT, $this->onSignal(...));

        $started = hrtime(true);
        try {
            $corpus = Corpus::open($this->config->logPath());
        } catch (StorageFailure $failure) {
            $this->logger->error('startup_failed', ['message' => $failure->getMessage()]);

            return 1;
        }

        $recovery = $corpus->recovery();
        $this->logger->info('replayed', [
            'log' => $this->config->logPath(),
            'status' => $recovery->status,
            'records' => $recovery->records,
            'reason' => $recovery->reason,
            'documents' => $corpus->stats()['documents'],
            'reindex_required' => $corpus->reindexRequired(),
            'ms' => round((hrtime(true) - $started) / 1e6, 1),
        ]);

        $api = new Api($corpus, $this->config->token, $this->logger, connections: fn (): int => $this->server?->connectionCount() ?? 0);
        $server = $this->server = new Server($api, $this->config->server);
        $address = $server->listen();
        $this->logger->info('listening', ['address' => $address]);
        $this->serving = true;

        while ($this->signals === 0 && !$api->storageFailed()) {
            $server->tick(1.0);
        }

        $exitCode = $this->drain($server);
        $corpus->close();

        if ($api->storageFailed()) {
            $this->logger->error('stopped', ['reason' => 'storage_failure']);

            return 1;
        }
        $this->logger->info('stopped', ['reason' => 'signal', 'exit_code' => $exitCode]);

        return $exitCode;
    }

    private function drain(Server $server): int
    {
        $server->drain();
        $this->logger->info('draining', ['connections' => $server->connectionCount(), 'deadline_s' => $this->config->drainSeconds]);

        $deadline = hrtime(true) / 1e9 + $this->config->drainSeconds;
        while ($server->connectionCount() > 0 && hrtime(true) / 1e9 < $deadline) {
            $server->tick(0.1);
        }

        $left = $server->connectionCount();
        $server->close();
        if ($left > 0) {
            $this->logger->error('drain_deadline_passed', ['connections' => $left]);

            return 1;
        }

        return 0;
    }

    private function onSignal(int $signal): void
    {
        $this->signals++;

        if (!$this->serving) {
            $this->logger->info('stopped', ['reason' => 'signal during replay']);
            exit(0);
        }
        if ($this->signals > 1) {
            $this->logger->error('stopped', ['reason' => 'second signal']);
            exit(1);
        }

        $this->logger->info('signal', ['signal' => $signal]);
    }
}
