<?php

declare(strict_types=1);

namespace PhpMiniSearch\Runtime;

use InvalidArgumentException;
use PhpMiniSearch\Http\ServerConfig;

/**
 * Service configuration: an optional PHP file returning an array, then
 * environment variables on top, then validation. The service refuses to start
 * on anything invalid — above all without a token of at least 32 bytes:
 * there is no "open by default" (docs/DECISIONS.md, Loopback and a token).
 *
 * | key              | environment                   | default        |
 * |------------------|-------------------------------|----------------|
 * | host             | MINI_SEARCH_HOST              | 127.0.0.1      |
 * | port             | MINI_SEARCH_PORT              | 8080           |
 * | data_dir         | MINI_SEARCH_DATA_DIR          | ./data         |
 * | token            | MINI_SEARCH_TOKEN             | (required)     |
 * | log_level        | MINI_SEARCH_LOG_LEVEL         | info           |
 * | drain_seconds    | MINI_SEARCH_DRAIN_SECONDS     | 10             |
 * | max_connections  | MINI_SEARCH_MAX_CONNECTIONS   | 256            |
 * | head_timeout     | MINI_SEARCH_HEAD_TIMEOUT      | 10             |
 * | body_timeout     | MINI_SEARCH_BODY_TIMEOUT      | 30             |
 * | idle_timeout     | MINI_SEARCH_IDLE_TIMEOUT      | 30             |
 * | write_timeout    | MINI_SEARCH_WRITE_TIMEOUT     | 30             |
 * | memory_limit     | MINI_SEARCH_MEMORY_LIMIT      | 768M           |
 *
 * memory_limit is set by the service itself: PHP's default of 128M is far
 * below the ~320 MB index of 10,000 documents, and the budget is 512 MB RSS
 * plus headroom for the peaks of building responses.
 */
final readonly class Config
{
    private const array DEFAULTS = [
        'host' => '127.0.0.1',
        'port' => 8080,
        'data_dir' => null,
        'token' => null,
        'log_level' => Logger::INFO,
        'drain_seconds' => 10,
        'max_connections' => 256,
        'head_timeout' => 10,
        'body_timeout' => 30,
        'idle_timeout' => 30,
        'write_timeout' => 30,
        'memory_limit' => '768M',
    ];

    /**
     * @param Logger::INFO|Logger::ERROR $logLevel
     */
    private function __construct(
        public string $dataDirectory,
        public string $token,
        public string $logLevel,
        public float $drainSeconds,
        public string $memoryLimit,
        public ServerConfig $server,
    ) {
    }

    /**
     * @param array<string, string> $environment usually getenv()
     */
    public static function load(?string $file, array $environment, string $workingDirectory): self
    {
        $values = self::DEFAULTS;

        if ($file !== null) {
            if (!is_file($file)) {
                throw new InvalidArgumentException("Config file {$file} does not exist.");
            }
            $fromFile = require $file;
            if (!is_array($fromFile)) {
                throw new InvalidArgumentException("Config file {$file} must return an array.");
            }
            foreach ($fromFile as $key => $value) {
                if (!array_key_exists((string) $key, self::DEFAULTS)) {
                    throw new InvalidArgumentException("Unknown config key {$key} in {$file}.");
                }
                $values[(string) $key] = $value;
            }
        }

        foreach (array_keys(self::DEFAULTS) as $key) {
            $variable = 'MINI_SEARCH_' . strtoupper($key);
            if (isset($environment[$variable]) && $environment[$variable] !== '') {
                $values[$key] = $environment[$variable];
            }
        }

        $token = self::text($values['token']);
        if (strlen($token) < 32) {
            throw new InvalidArgumentException('A token of at least 32 bytes is required (MINI_SEARCH_TOKEN or "token" in the config file).');
        }

        $logLevel = self::text($values['log_level']);
        if ($logLevel !== Logger::INFO && $logLevel !== Logger::ERROR) {
            throw new InvalidArgumentException('log_level must be "info" or "error".');
        }

        $dataDirectory = self::text($values['data_dir']);

        $memoryLimit = self::text($values['memory_limit']);
        if (preg_match('/^(-1|[1-9][0-9]*[KMG]?)$/', $memoryLimit) !== 1) {
            throw new InvalidArgumentException('memory_limit must look like 768M, 1G or -1.');
        }

        return new self(
            $dataDirectory === '' ? $workingDirectory . '/data' : $dataDirectory,
            $token,
            $logLevel,
            self::seconds($values['drain_seconds'], 'drain_seconds'),
            $memoryLimit,
            new ServerConfig(
                host: self::text($values['host']),
                port: self::integer($values['port'], 'port', 0, 65_535),
                maxConnections: self::integer($values['max_connections'], 'max_connections', 1, 100_000),
                headTimeout: self::seconds($values['head_timeout'], 'head_timeout'),
                bodyTimeout: self::seconds($values['body_timeout'], 'body_timeout'),
                idleTimeout: self::seconds($values['idle_timeout'], 'idle_timeout'),
                writeTimeout: self::seconds($values['write_timeout'], 'write_timeout'),
            ),
        );
    }

    public function logPath(): string
    {
        return rtrim($this->dataDirectory, '/') . '/search.log';
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private static function integer(mixed $value, string $key, int $min, int $max): int
    {
        if (!is_numeric($value) || (string) (int) $value !== (string) $value || (int) $value < $min || (int) $value > $max) {
            throw new InvalidArgumentException("{$key} must be an integer from {$min} to {$max}.");
        }

        return (int) $value;
    }

    private static function seconds(mixed $value, string $key): float
    {
        if (!is_numeric($value) || (float) $value <= 0) {
            throw new InvalidArgumentException("{$key} must be a positive number of seconds.");
        }

        return (float) $value;
    }
}
