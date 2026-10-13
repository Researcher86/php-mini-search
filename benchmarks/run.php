<?php

declare(strict_types=1);

/**
 * The baseline: one corpus, four load profiles, the startup time and the
 * memory, checked against the targets in docs/ARCHITECTURE.md.
 *
 *   php benchmarks/run.php <corpus.jsonl> [seconds-per-profile=8]
 *
 * It starts `bin/mini-search serve` as its own process, loads the corpus
 * through the API as a full reindex, then runs:
 *
 *   search      16 connections, POST /search only
 *   write       8 connections, PUT /documents/{id} with newer versions only
 *   mixed       16 connections, 95% search and 5% writes
 *   slow        the search profile again, alongside 200 connections that
 *               trickle a request head one byte every 500 ms
 *
 * and finally restarts the service to time a full replay. The load
 * generator runs in this process and the service in its own, so the two do
 * not share the service's single core.
 *
 * Prints a report, then one JSON line with every number.
 */

require __DIR__ . '/../vendor/autoload.php';

use PhpMiniSearch\Tests\Support\ServiceProcess;
use PhpMiniSearch\Tests\Support\TemporaryDirectory;

/**
 * One keep-alive connection of the load generator, with one request in flight.
 */
final class BenchConnection
{
    public string $kind = '';

    public int $sentAt = 0;

    public string $input = '';

    public string $output = '';

    /**
     * @param resource $socket
     */
    public function __construct(
        public mixed $socket,
    ) {
    }
}

/**
 * The latencies of one kind of request in one profile.
 */
final readonly class Latency
{
    public function __construct(
        public int $count,
        public float $p50,
        public float $p95,
        public float $p99,
    ) {
    }

    /**
     * @param list<float> $milliseconds
     */
    public static function of(array $milliseconds): self
    {
        sort($milliseconds);
        $at = static fn (float $q): float => round($milliseconds[(int) min(count($milliseconds) - 1, floor($q * count($milliseconds)))] ?? 0.0, 2);

        return new self(count($milliseconds), $at(0.50), $at(0.95), $at(0.99));
    }
}

/**
 * What one profile measured.
 */
final readonly class Profile
{
    /**
     * @param array<string, Latency> $latency by request kind
     */
    public function __construct(
        public int $requests,
        public int $errors,
        public float $seconds,
        public array $latency,
        public int $slowClientsLeft = 0,
    ) {
    }

    public function rps(): float
    {
        return $this->seconds > 0 ? round($this->requests / $this->seconds, 1) : 0.0;
    }
}

$corpusPath = $argv[1] ?? '';
$seconds = (float) ($argv[2] ?? 8);
if (!is_file($corpusPath)) {
    fwrite(STDERR, "Usage: php benchmarks/run.php <corpus.jsonl> [seconds-per-profile]\n");
    exit(2);
}

/** @var list<array{id: string, title: string, body: string}> $corpus */
$corpus = [];
foreach (file($corpusPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    $document = json_decode($line, true);
    if (is_array($document) && is_string($document['id'] ?? null) && is_string($document['title'] ?? null) && is_string($document['body'] ?? null)) {
        $corpus[] = ['id' => $document['id'], 'title' => $document['title'], 'body' => $document['body']];
    }
}

mt_srand(42);

/**
 * @param array<string, mixed> $body
 */
function jsonRequest(string $method, string $target, array $body): string
{
    $encoded = (string) json_encode($body, JSON_UNESCAPED_UNICODE);

    return "{$method} {$target} HTTP/1.1\r\nHost: x\r\nAuthorization: Bearer " . ServiceProcess::TOKEN
        . "\r\nContent-Type: application/json\r\nContent-Length: " . strlen($encoded) . "\r\n\r\n{$encoded}";
}

// Queries people would type: one to three words from a real title.
$queries = [];
for ($i = 0; $i < 1_000; $i++) {
    $words = preg_split('/\s+/u', $corpus[mt_rand(0, count($corpus) - 1)]['title'], -1, PREG_SPLIT_NO_EMPTY) ?: ['x'];
    shuffle($words);
    $queries[] = implode(' ', array_slice($words, 0, mt_rand(1, min(3, count($words)))));
}

/** @var array<string, int> $versions */
$versions = [];
$search = static fn (): array => ['search', jsonRequest('POST', '/search', ['query' => $queries[mt_rand(0, count($queries) - 1)], 'limit' => 10])];
$write = static function () use (&$versions, $corpus): array {
    $document = $corpus[mt_rand(0, count($corpus) - 1)];
    $versions[$document['id']] = ($versions[$document['id']] ?? 1) + 1;

    return ['write', jsonRequest('PUT', "/documents/{$document['id']}", [
        'source_version' => $versions[$document['id']],
        'title' => $document['title'],
        'body' => $document['body'],
    ])];
};

$directory = new TemporaryDirectory();
// Xdebug ships in the development image; measured with it, every number would be its overhead too.
$environment = ['MINI_SEARCH_LOG_LEVEL' => 'error', 'XDEBUG_MODE' => 'off'];

try {
    $service = new ServiceProcess($directory->path('data'), $environment, 600.0);

    // Load: a full reindex through the API, the way the platform does it.
    $generation = asInt($service->request('POST', '/reindex')['body']['generation'] ?? 1);
    $next = 0;
    $load = load($service->address, 8, INF, static function () use (&$next, $corpus, $generation): ?array {
        $document = $corpus[$next++] ?? null;

        return $document === null ? null : ['write', jsonRequest('PUT', "/documents/{$document['id']}", [
            'source_version' => 1, 'title' => $document['title'], 'body' => $document['body'], 'generation' => $generation,
        ])];
    });
    $service->request('POST', "/reindex/{$generation}/complete");
    $stats = $service->request('GET', '/stats')['body'];
    $terms = asInt($stats['terms'] ?? 0);
    $log = $stats['log'] ?? [];
    $logBytes = is_array($log) ? asInt($log['bytes'] ?? 0) : 0;
    $rssAfterLoad = memoryOf($service->pid());

    $profiles = [
        'search' => load($service->address, 16, $seconds, $search),
        'write' => load($service->address, 8, $seconds, $write),
        'mixed' => load($service->address, 16, $seconds, static fn (): array => mt_rand(1, 100) <= 95 ? $search() : $write()),
        'slow' => load($service->address, 16, $seconds, $search, slowClients: 200),
    ];
    $peakRss = memoryOf($service->pid(), 'VmHWM');

    $service->signal(SIGTERM);
    $service->wait();

    $started = microtime(true);
    $restarted = new ServiceProcess($directory->path('data'), $environment, 600.0);
    $startup = round(microtime(true) - $started, 2);
    $replayRecords = 0;
    $replayMs = 0.0;
    foreach ($restarted->logLines() as $line) {
        if (($line['event'] ?? null) === 'replayed') {
            $replayRecords = asInt($line['records'] ?? 0);
            $replayMs = asFloat($line['ms'] ?? 0);
        }
    }
    $rssAfterReplay = memoryOf($restarted->pid());
    $restarted->signal(SIGTERM);
    $restarted->wait();
} finally {
    $directory->remove();
}

$errors = array_sum(array_map(static fn (Profile $profile): int => $profile->errors, $profiles));
$p99 = $profiles['search']->latency['search']->p99 ?? 0.0;
$slowP99 = $profiles['slow']->latency['search']->p99 ?? 0.0;
$targets = [
    'peak RSS ≤ 512 MB' => $peakRss <= 512,
    'startup ≤ 10 s' => $startup <= 10,
    'p99 with 200 slow clients ≤ 2 × p99 without' => $slowP99 <= 2 * $p99,
    'no errors' => $errors === 0,
];

printf("\n%s: %d documents, %d terms, log %.1f MB, PHP %s, %d CPUs\n\n", basename($corpusPath), count($corpus), $terms, $logBytes / 1_048_576, PHP_VERSION, (int) shell_exec('getconf _NPROCESSORS_ONLN'));
printf("%-12s %9s %8s %7s %9s %9s %9s\n", 'profile', 'requests', 'rps', 'errors', 'p50 ms', 'p95 ms', 'p99 ms');
foreach (['load' => $load] + $profiles as $name => $profile) {
    foreach ($profile->latency as $kind => $latency) {
        $label = $name === 'mixed' ? "{$name}/{$kind}" : $name;
        printf("%-12s %9d %8.1f %7d %9.2f %9.2f %9.2f\n", $label, $latency->count, $profile->rps(), $profile->errors, $latency->p50, $latency->p95, $latency->p99);
    }
}
printf("\nRSS after load %.1f MB, peak %.1f MB, after replay %.1f MB\n", $rssAfterLoad, $peakRss, $rssAfterReplay);
printf("startup %.2f s (replay of %d records in %.0f ms)\n\n", $startup, $replayRecords, $replayMs);
foreach ($targets as $target => $met) {
    printf("  %s %s\n", $met ? 'met    ' : 'MISSED ', $target);
}
echo "\n", json_encode([
    'corpus' => basename($corpusPath), 'documents' => count($corpus), 'terms' => $terms, 'log_bytes' => $logBytes,
    'profiles' => ['load' => $load] + $profiles, 'rss_after_load_mb' => $rssAfterLoad, 'peak_rss_mb' => $peakRss,
    'rss_after_replay_mb' => $rssAfterReplay, 'startup_s' => $startup, 'replay_records' => $replayRecords,
    'replay_ms' => $replayMs, 'targets' => $targets,
], JSON_UNESCAPED_UNICODE), "\n";

/**
 * Drives keep-alive connections with one request in flight each, until
 * $seconds pass or $next returns null. Optionally keeps $slowClients
 * connections trickling a request head, a byte every 500 ms.
 *
 * @param Closure(): (array{string, string}|null) $next [kind, raw request]
 */
function load(string $address, int $connections, float $seconds, Closure $next, int $slowClients = 0): Profile
{
    $open = static function () use ($address) {
        $socket = stream_socket_client("tcp://{$address}", $code, $message, 5.0);
        if ($socket === false) {
            throw new RuntimeException("connect: {$message}");
        }
        stream_set_blocking($socket, false);

        return $socket;
    };

    $slowHead = "GET /health/live HTTP/1.1\r\nHost: x\r\nX-Slow: " . str_repeat('s', 200);
    /** @var list<array{socket: resource, sent: int, at: float}> $slow */
    $slow = [];
    for ($i = 0; $i < $slowClients; $i++) {
        $slow[] = ['socket' => $open(), 'sent' => 0, 'at' => 0.0];
    }

    /** @var array<int, BenchConnection> $active */
    $active = [];
    /** @var array<string, list<float>> $latencies */
    $latencies = [];
    $requests = 0;
    $errors = 0;
    $started = microtime(true);
    $deadline = $started + $seconds;
    $exhausted = false;

    $send = static function (BenchConnection $connection) use ($next, &$exhausted): bool {
        $request = $exhausted ? null : $next();
        if ($request === null) {
            $exhausted = true;

            return false;
        }
        [$connection->kind, $connection->output] = $request;
        $connection->sentAt = hrtime(true);
        $connection->input = '';

        return true;
    };

    for ($slot = 0; $slot < $connections; $slot++) {
        $connection = new BenchConnection($open());
        if ($send($connection)) {
            $active[$slot] = $connection;
        } else {
            fclose($connection->socket);
        }
    }

    while ($active !== [] && microtime(true) < $deadline) {
        $now = microtime(true);
        foreach ($slow as $i => $client) {
            if ($now - $client['at'] < 0.5 || $client['sent'] >= strlen($slowHead)) {
                continue;
            }
            if (@fwrite($client['socket'], $slowHead[$client['sent']]) === false || feof($client['socket'])) {
                $slow[$i] = ['socket' => $open(), 'sent' => 0, 'at' => $now];
                continue;
            }
            $slow[$i]['sent']++;
            $slow[$i]['at'] = $now;
        }

        $read = $write = [];
        foreach ($active as $connection) {
            $connection->output === '' ? $read[] = $connection->socket : $write[] = $connection->socket;
        }
        $except = null;
        if (@stream_select($read, $write, $except, 0, 20_000) === false) {
            continue;
        }

        foreach ($active as $slot => $connection) {
            if (in_array($connection->socket, $write, true)) {
                $written = @fwrite($connection->socket, $connection->output);
                $connection->output = $written === false ? '' : substr($connection->output, $written);
            }
            if (!in_array($connection->socket, $read, true)) {
                continue;
            }

            $chunk = @fread($connection->socket, 1 << 20);
            if ($chunk === false || ($chunk === '' && feof($connection->socket))) {
                $errors++;
                fclose($connection->socket);
                $connection->socket = $open();
                if (!$send($connection)) {
                    fclose($connection->socket);
                    unset($active[$slot]);
                }
                continue;
            }
            $connection->input .= $chunk;
            $end = strpos($connection->input, "\r\n\r\n");
            if ($end === false || preg_match('/content-length: (\d+)/i', substr($connection->input, 0, $end), $length) !== 1
                || strlen($connection->input) < $end + 4 + (int) $length[1]) {
                continue;
            }

            $latencies[$connection->kind][] = (hrtime(true) - $connection->sentAt) / 1e6;
            $requests++;
            if (!str_starts_with($connection->input, 'HTTP/1.1 200')) {
                $errors++;
            }
            if (!$send($connection)) {
                fclose($connection->socket);
                unset($active[$slot]);
            }
        }
    }

    $elapsed = microtime(true) - $started;
    foreach ($active as $connection) {
        fclose($connection->socket);
    }
    $slowLeft = 0;
    foreach ($slow as $client) {
        $slowLeft += feof($client['socket']) ? 0 : 1;
        fclose($client['socket']);
    }

    return new Profile(
        $requests,
        $errors,
        round($elapsed, 2),
        array_map(Latency::of(...), $latencies),
        $slowLeft,
    );
}

function memoryOf(int $pid, string $field = 'VmRSS'): float
{
    $status = (string) @file_get_contents("/proc/{$pid}/status");

    return preg_match("/{$field}:\\s+(\\d+) kB/", $status, $match) === 1 ? round((int) $match[1] / 1024, 1) : 0.0;
}

function asInt(mixed $value): int
{
    return is_numeric($value) ? (int) $value : 0;
}

function asFloat(mixed $value): float
{
    return is_numeric($value) ? (float) $value : 0.0;
}
