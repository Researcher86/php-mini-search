<?php

declare(strict_types=1);

namespace PhpMiniSearch\Runtime;

use Closure;

/**
 * The PHP extensions the service cannot run without, checked before anything
 * else happens.
 *
 * Composer's `ext-*` requirements are checked at `composer install` time, on
 * the machine that installs. The process that serves requests may run with a
 * different `php.ini`, a different SAPI, or a different binary altogether, and
 * then the first sign of a missing `intl` would be a fatal "Class Normalizer
 * not found" on the first document indexed. The check runs again at startup
 * so the failure is one clear line instead.
 *
 * Each extension is listed with the reason it is required, because the
 * reason is what an operator needs to decide whether to install it.
 */
final class RequiredExtensions
{
    /** @var array<string, string> extension => why the service needs it */
    public const array REQUIRED = [
        'intl' => 'Unicode NFKC normalization of every document and query (Normalizer)',
        'mbstring' => 'lowercasing text in any script (mb_strtolower)',
        'pcntl' => 'graceful shutdown on SIGTERM and SIGINT',
    ];

    /** @var Closure(string): bool */
    private readonly Closure $isLoaded;

    /**
     * @param (Closure(string): bool)|null $isLoaded defaults to extension_loaded(); tests pass their own
     */
    public function __construct(
        ?Closure $isLoaded = null,
    ) {
        $this->isLoaded = $isLoaded ?? extension_loaded(...);
    }

    /**
     * @return array<string, string> the missing extensions, each with its reason
     */
    public function missing(): array
    {
        return array_filter(
            self::REQUIRED,
            fn (string $extension): bool => !($this->isLoaded)($extension),
            ARRAY_FILTER_USE_KEY,
        );
    }
}
