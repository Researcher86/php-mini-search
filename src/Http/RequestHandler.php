<?php

declare(strict_types=1);

namespace PhpMiniSearch\Http;

/**
 * What the server calls for every request. The server knows HTTP and nothing
 * else; routing, JSON and search live behind this interface.
 */
interface RequestHandler
{
    public function handle(Request $request): Response;

    /**
     * The response to a request the server could not accept. The server adds
     * `Connection: close` and closes the connection after sending it.
     */
    public function reject(HttpError $error): Response;
}
