<?php

declare(strict_types=1);

namespace PhpMiniSearch\Tests\Runtime;

use PhpMiniSearch\Runtime\RequiredExtensions;
use PHPUnit\Framework\TestCase;

final class RequiredExtensionsTest extends TestCase
{
    public function testNothingIsMissingWhenEveryExtensionIsLoaded(): void
    {
        $extensions = new RequiredExtensions(fn (string $extension): bool => true);

        $this->assertSame([], $extensions->missing());
    }

    public function testAMissingExtensionIsReportedWithItsReason(): void
    {
        $extensions = new RequiredExtensions(fn (string $extension): bool => $extension !== 'intl');

        $this->assertSame(['intl' => RequiredExtensions::REQUIRED['intl']], $extensions->missing());
    }

    /**
     * The development image is the environment CI checks, so it must carry
     * everything the service needs.
     */
    public function testTheDevelopmentImageHasEveryRequiredExtension(): void
    {
        $this->assertSame([], (new RequiredExtensions())->missing());
    }
}
