<?php

declare(strict_types=1);

namespace PhpMiniSearch\Api;

use RuntimeException;

/**
 * An answer the API gives instead of a result: an HTTP status and one of the
 * stable error codes in docs/API.md. Thrown anywhere in a handler and turned
 * into the error body in one place.
 */
final class ApiError extends RuntimeException
{
    /**
     * @param array<string, scalar> $details
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $details = [],
        public readonly array $headers = [],
    ) {
        parent::__construct($message);
    }

    public static function invalid(string $field, string $rule, string $message): self
    {
        return new self(422, 'validation_failed', $message, ['field' => $field, 'rule' => $rule]);
    }
}
