<?php

declare(strict_types=1);

namespace PhpMiniSearch\Api;

use Closure;
use JsonException;
use PhpMiniSearch\Corpus\Corpus;
use PhpMiniSearch\Corpus\GenerationNotOpen;
use PhpMiniSearch\Corpus\QueryRejected;
use PhpMiniSearch\Corpus\ReindexRequired;
use PhpMiniSearch\Corpus\WriteResult;
use PhpMiniSearch\Http\HttpError;
use PhpMiniSearch\Http\Request;
use PhpMiniSearch\Http\RequestHandler;
use PhpMiniSearch\Http\Response;
use PhpMiniSearch\Runtime\Logger;
use PhpMiniSearch\Storage\StorageFailure;

/**
 * The HTTP contract of docs/API.md, on top of the corpus.
 *
 * Every request goes through the same steps, and the order is part of the
 * contract:
 *
 *  1. a request id: the client's X-Request-Id if it is sane, else a new one;
 *  2. the token, for everything but /health/* — before routing, so that
 *     404 and 405 do not tell a stranger which paths exist;
 *  3. the route (404 route_not_found, 405 method_not_allowed with Allow);
 *  4. for a body, Content-Type (415) and JSON (400 invalid_json);
 *  5. the fields (422 validation_failed, naming the field and the rule);
 *  6. the corpus.
 *
 * Every error body has the same shape, built in one place:
 * {"error": {"code", "message", "details"}, "request_id"}. Clients branch on
 * the code, never on the message.
 *
 * A StorageFailure is the one error the API cannot recover from: the answer
 * is 503 storage_failure, and storageFailed() tells the runtime to stop the
 * process once the response is out (docs/FAILURE-MODEL.md).
 */
final class Api implements RequestHandler
{
    private const string ID = '/^[A-Za-z0-9._-]{1,128}$/';

    private const int MAX_TITLE_BYTES = 1_024;

    private const int MAX_BODY_BYTES = 65_536;

    private const int MAX_LIMIT = 100;

    private const int MAX_DEPTH = 1_000;

    private const string RETRY_AFTER = '10';

    private bool $storageFailed = false;

    private readonly float $startedAt;

    /**
     * @param Closure(): int $connections how many connections the server holds, for /stats
     */
    public function __construct(
        private readonly Corpus $corpus,
        private readonly string $token,
        private readonly Logger $logger,
        private readonly Metrics $metrics = new Metrics(),
        private readonly ?Closure $connections = null,
    ) {
        $this->startedAt = microtime(true);
    }

    public function handle(Request $request): Response
    {
        $started = hrtime(true);
        $requestId = $this->requestId($request);
        $route = $request->method . ' ' . $this->routeName($request->path());

        try {
            $response = $this->route($request);
        } catch (ApiError $error) {
            $response = $this->error($error);
        } catch (StorageFailure $failure) {
            $this->storageFailed = true;
            $this->logger->error('storage_failure', ['message' => $failure->getMessage(), 'request_id' => $requestId]);
            $response = $this->error(new ApiError(503, 'storage_failure', 'The log could not be written; the service is stopping.'));
        }

        $response = $this->withRequestId($response, $requestId);
        $milliseconds = (hrtime(true) - $started) / 1e6;
        $this->metrics->record($route, $response->status, $milliseconds);
        $this->logger->request($request->method, $request->path(), $response->status, $milliseconds, $requestId);

        return $response;
    }

    public function reject(HttpError $error): Response
    {
        $requestId = bin2hex(random_bytes(8));
        $this->logger->request('-', '-', $error->status, 0.0, $requestId);

        return $this->withRequestId($this->error(new ApiError($error->status, $error->code, $error->message)), $requestId);
    }

    /** True once the log has failed: the process must stop after this response. */
    public function storageFailed(): bool
    {
        return $this->storageFailed;
    }

    private function route(Request $request): Response
    {
        $path = $request->path();
        $method = $request->method;

        if ($path === '/health/live') {
            $this->allow($method, ['GET']);

            return Response::json(200, ['status' => 'ok']);
        }
        if ($path === '/health/ready') {
            $this->allow($method, ['GET']);

            return $this->ready();
        }

        $this->authenticate($request);

        if ($path === '/stats') {
            $this->allow($method, ['GET']);

            return Response::json(200, $this->stats());
        }
        if (preg_match('#^/documents/([^/]+)$#', $path, $match) === 1) {
            $this->allow($method, ['PUT', 'DELETE', 'GET']);
            $id = $this->id($match[1]);

            return match ($method) {
                'PUT' => $this->put($id, $request),
                'DELETE' => $this->delete($id, $request),
                default => $this->describe($id),
            };
        }
        if ($path === '/search') {
            $this->allow($method, ['POST']);

            return $this->search($request);
        }
        if ($path === '/reindex') {
            $this->allow($method, ['POST']);

            return Response::json(200, $this->corpus->beginReindex());
        }
        if (preg_match('#^/reindex/([0-9]{1,18})/complete$#', $path, $match) === 1) {
            $this->allow($method, ['POST']);

            return $this->complete((int) $match[1]);
        }

        throw new ApiError(404, 'route_not_found', "No route for {$path}.");
    }

    private function ready(): Response
    {
        $reason = $this->corpus->reindexRequired();
        if ($reason === null) {
            return Response::json(200, ['status' => 'ready']);
        }

        return Response::json(503, ['status' => 'reindex_required', 'reason' => $reason], ['Retry-After' => self::RETRY_AFTER]);
    }

    private function put(string $id, Request $request): Response
    {
        $body = $this->json($request);
        $this->onlyFields($body, ['source_version', 'title', 'body', 'generation']);

        $result = $this->corpus->put(
            $id,
            $this->positiveInt($body, 'source_version'),
            $this->string($body, 'title', self::MAX_TITLE_BYTES),
            $this->string($body, 'body', self::MAX_BODY_BYTES),
            array_key_exists('generation', $body) ? $this->positiveInt($body, 'generation') : null,
        );

        return Response::json(200, $this->written($result));
    }

    private function delete(string $id, Request $request): Response
    {
        $version = $request->query()['source_version'] ?? null;
        if (!is_string($version) || preg_match('/^[1-9][0-9]{0,17}$/', $version) !== 1) {
            throw ApiError::invalid('source_version', 'positive_integer', 'source_version must be a positive integer in the query string.');
        }

        $result = $this->corpus->delete($id, (int) $version);

        return Response::json(200, ['id' => $result->id, 'applied' => $result->applied, 'source_version' => $result->sourceVersion]);
    }

    private function describe(string $id): Response
    {
        $document = $this->corpus->describe($id);
        if ($document === null) {
            throw new ApiError(404, 'document_not_found', "No document {$id} has ever been indexed.");
        }

        return Response::json(200, $document);
    }

    private function search(Request $request): Response
    {
        $body = $this->json($request);
        $this->onlyFields($body, ['query', 'limit', 'offset']);

        $query = $this->string($body, 'query', self::MAX_BODY_BYTES);
        $limit = array_key_exists('limit', $body) ? $this->positiveInt($body, 'limit') : 10;
        $offset = array_key_exists('offset', $body) ? $this->naturalInt($body, 'offset') : 0;

        if ($limit > self::MAX_LIMIT) {
            throw ApiError::invalid('limit', 'max', 'limit must be at most ' . self::MAX_LIMIT . '.');
        }
        if ($offset + $limit > self::MAX_DEPTH) {
            throw new ApiError(422, 'offset_too_deep', 'offset + limit must be at most ' . self::MAX_DEPTH . '.');
        }

        try {
            $page = $this->corpus->search($query, $limit, $offset);
        } catch (QueryRejected $rejected) {
            throw new ApiError(422, $rejected->reason, $rejected->getMessage());
        } catch (ReindexRequired $required) {
            throw new ApiError(503, 'reindex_required', $required->getMessage(), ['reason' => $required->reason], ['Retry-After' => self::RETRY_AFTER]);
        }

        return Response::json(200, [
            'results' => array_map(
                fn ($hit) => ['id' => $hit->id, 'score' => round($hit->score, 6), 'source_version' => $hit->sourceVersion],
                $page->hits,
            ),
            'total' => $page->total,
            'limit' => $limit,
            'offset' => $offset,
        ]);
    }

    private function complete(int $generation): Response
    {
        try {
            $removed = $this->corpus->completeReindex($generation);
        } catch (GenerationNotOpen $notOpen) {
            throw new ApiError(409, 'generation_not_open', $notOpen->getMessage());
        }

        return Response::json(200, ['generation' => $generation, 'removed' => $removed]);
    }

    /**
     * @return array<string, mixed>
     */
    private function stats(): array
    {
        return $this->corpus->stats() + [
            'connections' => $this->connections === null ? 0 : ($this->connections)(),
            'requests' => $this->metrics->requests(),
            'latency_ms' => $this->metrics->latency(),
            'rss_bytes' => self::rss(),
            'uptime_s' => (int) (microtime(true) - $this->startedAt),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function written(WriteResult $result): array
    {
        return [
            'id' => $result->id,
            'applied' => $result->applied,
            'source_version' => $result->sourceVersion,
            'generation' => $result->generation,
        ];
    }

    private function authenticate(Request $request): void
    {
        $header = $request->header('authorization') ?? '';
        $given = str_starts_with($header, 'Bearer ') ? substr($header, 7) : '';

        if (!hash_equals($this->token, $given)) {
            throw new ApiError(401, 'unauthorized', 'A valid "Authorization: Bearer <token>" header is required.');
        }
    }

    /**
     * @param list<string> $methods
     */
    private function allow(string $method, array $methods): void
    {
        if (!in_array($method, $methods, true)) {
            throw new ApiError(405, 'method_not_allowed', "{$method} is not allowed here.", [], ['Allow' => implode(', ', $methods)]);
        }
    }

    private function id(string $id): string
    {
        if (preg_match(self::ID, $id) !== 1) {
            throw ApiError::invalid('id', 'pattern', 'The id must be 1–128 characters of A–Z, a–z, 0–9, ".", "_" or "-".');
        }

        return $id;
    }

    /**
     * @return array<string, mixed>
     */
    private function json(Request $request): array
    {
        $type = strtolower(trim(explode(';', $request->header('content-type') ?? '')[0]));
        if ($type !== 'application/json') {
            throw new ApiError(415, 'unsupported_media_type', 'Content-Type must be application/json.');
        }

        try {
            $data = json_decode($request->body, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ApiError(400, 'invalid_json', 'The body is not valid JSON.');
        }

        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            throw new ApiError(400, 'invalid_json', 'The body must be a JSON object.');
        }

        /** @var array<string, mixed> $data */
        return $data;
    }

    /**
     * @param array<string, mixed> $body
     * @param list<string> $allowed
     */
    private function onlyFields(array $body, array $allowed): void
    {
        foreach (array_keys($body) as $field) {
            if (!in_array($field, $allowed, true)) {
                throw ApiError::invalid((string) $field, 'unknown', "Unknown field {$field}.");
            }
        }
    }

    /**
     * @param array<string, mixed> $body
     */
    private function string(array $body, string $field, int $maxBytes): string
    {
        $value = $body[$field] ?? null;
        if (!is_string($value)) {
            throw ApiError::invalid($field, 'string', "{$field} is required and must be a string.");
        }
        if (strlen($value) > $maxBytes) {
            throw ApiError::invalid($field, 'max_bytes', "{$field} must be at most {$maxBytes} bytes.");
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function positiveInt(array $body, string $field): int
    {
        $value = $body[$field] ?? null;
        if (!is_int($value) || $value < 1) {
            throw ApiError::invalid($field, 'positive_integer', "{$field} must be a positive integer.");
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function naturalInt(array $body, string $field): int
    {
        $value = $body[$field] ?? null;
        if (!is_int($value) || $value < 0) {
            throw ApiError::invalid($field, 'non_negative_integer', "{$field} must be an integer of 0 or more.");
        }

        return $value;
    }

    private function error(ApiError $error): Response
    {
        $body = ['code' => $error->errorCode, 'message' => $error->getMessage()];
        if ($error->details !== []) {
            $body['details'] = $error->details;
        }

        return Response::json($error->status, ['error' => $body], $error->headers);
    }

    private function withRequestId(Response $response, string $requestId): Response
    {
        $headers = $response->headers + ['X-Request-Id' => $requestId];
        $body = $response->body;

        if ($response->status >= 400) {
            $data = json_decode($body, true);
            if (is_array($data)) {
                $data['request_id'] = $requestId;
                $body = (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }

        return new Response($response->status, $headers, $body);
    }

    private function requestId(Request $request): string
    {
        $given = $request->header('x-request-id') ?? '';

        return preg_match('/^[A-Za-z0-9._-]{1,128}$/', $given) === 1 ? $given : bin2hex(random_bytes(8));
    }

    /**
     * The route a path belongs to, for metrics: ids and generation numbers
     * replaced, so /stats has one line per endpoint, not per document.
     */
    private function routeName(string $path): string
    {
        return (string) preg_replace(['#^/documents/[^/]+$#', '#^/reindex/[0-9]+/complete$#'], ['/documents/{id}', '/reindex/{generation}/complete'], $path);
    }

    private static function rss(): int
    {
        $status = @file_get_contents('/proc/self/status');
        if (is_string($status) && preg_match('/VmRSS:\s+(\d+) kB/', $status, $match) === 1) {
            return (int) $match[1] * 1_024;
        }

        return memory_get_usage(true);
    }
}
