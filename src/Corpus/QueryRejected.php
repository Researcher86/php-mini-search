<?php

declare(strict_types=1);

namespace PhpMiniSearch\Corpus;

use InvalidArgumentException;

/**
 * The query cannot be run as written. The reason is one of the stable error
 * codes in docs/API.md, so the API can pass it straight through.
 */
final class QueryRejected extends InvalidArgumentException
{
    public const string EMPTY_QUERY = 'empty_query';

    public const string TOO_MANY_TERMS = 'too_many_terms';

    /**
     * @param self::EMPTY_QUERY|self::TOO_MANY_TERMS $reason
     */
    public function __construct(
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }
}
