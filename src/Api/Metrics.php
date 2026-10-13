<?php

declare(strict_types=1);

namespace PhpMiniSearch\Api;

/**
 * Request counts and latencies for GET /stats.
 *
 * Latency percentiles come from the last 1,024 requests, kept in a ring:
 * enough to see the service as it is now, with a fixed amount of memory, and
 * nothing to configure.
 */
final class Metrics
{
    private const int WINDOW = 1_024;

    /** @var array<string, int> "METHOD /route status" → count */
    private array $requests = [];

    /** @var array<int, float> milliseconds, a ring of the last WINDOW requests */
    private array $latencies = [];

    private int $next = 0;

    public function record(string $route, int $status, float $milliseconds): void
    {
        $key = "{$route} {$status}";
        $this->requests[$key] = ($this->requests[$key] ?? 0) + 1;

        if (count($this->latencies) < self::WINDOW) {
            $this->latencies[] = $milliseconds;
        } else {
            $this->latencies[$this->next] = $milliseconds;
        }
        $this->next = ($this->next + 1) % self::WINDOW;
    }

    /**
     * @return array<string, int>
     */
    public function requests(): array
    {
        ksort($this->requests);

        return $this->requests;
    }

    /**
     * @return array{p50: float, p95: float, p99: float}
     */
    public function latency(): array
    {
        $sorted = $this->latencies;
        sort($sorted);
        $at = function (float $quantile) use ($sorted): float {
            if ($sorted === []) {
                return 0.0;
            }

            return round($sorted[(int) min(count($sorted) - 1, floor($quantile * count($sorted)))], 3);
        };

        return ['p50' => $at(0.50), 'p95' => $at(0.95), 'p99' => $at(0.99)];
    }
}
