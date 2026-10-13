<?php

declare(strict_types=1);

namespace PhpMiniSearch\Tests\Runtime;

use InvalidArgumentException;
use PhpMiniSearch\Runtime\Config;
use PhpMiniSearch\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    private const string TOKEN = '0123456789abcdef0123456789abcdef';

    public function testDefaultsNeedOnlyAToken(): void
    {
        $config = Config::load(null, ['MINI_SEARCH_TOKEN' => self::TOKEN], '/srv');

        $this->assertSame('/srv/data/search.log', $config->logPath());
        $this->assertSame('127.0.0.1', $config->server->host);
        $this->assertSame(8080, $config->server->port);
        $this->assertSame(10.0, $config->drainSeconds);
    }

    public function testTheServiceRefusesToStartWithoutAToken(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('32 bytes');

        Config::load(null, [], '/srv');
    }

    public function testAShortTokenIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Config::load(null, ['MINI_SEARCH_TOKEN' => 'short'], '/srv');
    }

    public function testTheEnvironmentOverridesTheFile(): void
    {
        $directory = new TemporaryDirectory();
        $file = $directory->path('config.php');
        file_put_contents($file, '<?php return ["port" => 9000, "token" => "' . self::TOKEN . '", "idle_timeout" => 5];');

        try {
            $config = Config::load($file, ['MINI_SEARCH_PORT' => '9100', 'MINI_SEARCH_DATA_DIR' => '/var/lib/search'], '/srv');
        } finally {
            $directory->remove();
        }

        $this->assertSame(9100, $config->server->port);
        $this->assertSame(5.0, $config->server->idleTimeout);
        $this->assertSame('/var/lib/search/search.log', $config->logPath());
    }

    public function testAnUnknownKeyInTheFileIsAMistake(): void
    {
        $directory = new TemporaryDirectory();
        $file = $directory->path('config.php');
        file_put_contents($file, '<?php return ["prot" => 9000];');

        try {
            $this->expectException(InvalidArgumentException::class);
            Config::load($file, ['MINI_SEARCH_TOKEN' => self::TOKEN], '/srv');
        } finally {
            $directory->remove();
        }
    }

    public function testInvalidNumbersAreRefused(): void
    {
        foreach (['MINI_SEARCH_PORT' => '80a', 'MINI_SEARCH_IDLE_TIMEOUT' => '0', 'MINI_SEARCH_LOG_LEVEL' => 'debug'] as $variable => $value) {
            try {
                Config::load(null, ['MINI_SEARCH_TOKEN' => self::TOKEN, $variable => $value], '/srv');
                $this->fail("{$variable}={$value} should be refused");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
