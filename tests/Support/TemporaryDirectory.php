<?php

declare(strict_types=1);

namespace PhpMiniSearch\Tests\Support;

/**
 * A fresh directory under the system temp dir, removed with everything in it.
 */
final class TemporaryDirectory
{
    private readonly string $root;

    public function __construct()
    {
        $this->root = sys_get_temp_dir() . '/mini-search-' . bin2hex(random_bytes(6));
        mkdir($this->root);
    }

    public function path(string $name): string
    {
        return $this->root . '/' . $name;
    }

    public function remove(): void
    {
        self::removeTree($this->root);
    }

    private static function removeTree(string $directory): void
    {
        foreach (glob($directory . '/{,.}[!.]*', GLOB_BRACE) ?: [] as $entry) {
            is_dir($entry) && !is_link($entry) ? self::removeTree($entry) : unlink($entry);
        }
        rmdir($directory);
    }
}
