<?php

declare(strict_types=1);

namespace PhpMiniSearch\Tests\Support;

use PhpMiniSearch\Http\HttpError;
use PhpMiniSearch\Http\Request;
use PhpMiniSearch\Http\RequestHandler;
use PhpMiniSearch\Http\Response;
use RuntimeException;

/**
 * A handler that answers with what it received, so server tests can see
 * exactly which request arrived. `/boom` throws, `/big?bytes=N` answers with
 * N bytes.
 */
final class EchoHandler implements RequestHandler
{
    public function handle(Request $request): Response
    {
        if ($request->path() === '/boom') {
            throw new RuntimeException('boom');
        }
        if ($request->path() === '/big') {
            $bytes = $request->query()['bytes'] ?? 0;

            return new Response(200, [], str_repeat('x', is_numeric($bytes) ? (int) $bytes : 0));
        }

        return Response::json(200, [
            'method' => $request->method,
            'target' => $request->target,
            'version' => $request->version,
            'body' => $request->body,
        ]);
    }

    public function reject(HttpError $error): Response
    {
        return Response::json($error->status, ['error' => ['code' => $error->code, 'message' => $error->message]]);
    }
}
