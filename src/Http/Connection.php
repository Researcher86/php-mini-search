<?php

declare(strict_types=1);

namespace PhpMiniSearch\Http;

/**
 * The state the loop keeps per client: its parser, the bytes still to send,
 * and the moments each deadline counts from.
 */
final class Connection
{
    public string $output = '';

    public bool $closeAfterWrite = false;

    public ?float $headStartedAt = null;

    public ?float $bodyStartedAt = null;

    public ?float $writeStartedAt = null;

    /**
     * @param resource $socket
     */
    public function __construct(
        public readonly mixed $socket,
        public readonly RequestParser $parser,
        public float $idleSince,
    ) {
    }

    /**
     * Read only when there is somewhere for the bytes to go: not while a
     * response is still being written (the next pipelined request waits for
     * it), and not while a whole request is already buffered.
     */
    public function wantsRead(): bool
    {
        return $this->output === '' && !$this->closeAfterWrite && !$this->parser->hasBufferedRequest();
    }

    public function isIdle(): bool
    {
        return $this->output === '' && !$this->parser->inHead() && !$this->parser->inBody();
    }
}
