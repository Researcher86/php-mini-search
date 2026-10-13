<?php

declare(strict_types=1);

namespace PhpMiniSearch\Tests;

use PhpMiniSearch\Runtime\RequiredExtensions;
use PHPUnit\Framework\TestCase;

final class ProjectSetupTest extends TestCase
{
    public function testProjectLoads(): void
    {
        $this->assertTrue(class_exists(RequiredExtensions::class));
    }

    /**
     * The entry point is what the platform runs; with no command it must
     * get as far as the usage line, which proves the autoloader and the
     * extension check both work from bin/.
     */
    public function testTheEntryPointRunsAndPrintsUsage(): void
    {
        $output = [];
        exec(PHP_BINARY . ' ' . escapeshellarg(__DIR__ . '/../bin/mini-search') . ' 2>&1', $output, $exitCode);

        $this->assertSame(2, $exitCode);
        $this->assertSame(['Usage: mini-search <serve|compact|check>'], $output);
    }
}
