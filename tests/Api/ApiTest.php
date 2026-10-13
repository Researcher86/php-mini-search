<?php

declare(strict_types=1);

namespace PhpMiniSearch\Tests\Api;

use PhpMiniSearch\Api\Api;
use PhpMiniSearch\Corpus\Corpus;
use PhpMiniSearch\Http\HttpError;
use PhpMiniSearch\Http\Request;
use PhpMiniSearch\Http\Response;
use PhpMiniSearch\Runtime\Logger;
use PhpMiniSearch\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * docs/API.md, endpoint by endpoint and error code by error code, without a
 * socket: requests go straight into the handler.
 */
final class ApiTest extends TestCase
{
    private const string TOKEN = '0123456789abcdef0123456789abcdef';

    private TemporaryDirectory $directory;

    private Corpus $corpus;

    private Api $api;

    protected function setUp(): void
    {
        $this->directory = new TemporaryDirectory();
        $this->corpus = Corpus::open($this->directory->path('search.log'));
        $log = fopen('php://memory', 'w+');
        $this->assertIsResource($log);
        $this->api = new Api($this->corpus, self::TOKEN, new Logger($log));
    }

    protected function tearDown(): void
    {
        $this->corpus->close();
        $this->directory->remove();
    }

    public function testLivenessNeedsNoToken(): void
    {
        $this->assertResponse(200, ['status' => 'ok'], $this->call('GET', '/health/live', token: null));
    }

    public function testReadinessReportsAFirstStartUntilAReindexCompletes(): void
    {
        $response = $this->call('GET', '/health/ready', token: null);
        $this->assertResponse(503, ['status' => 'reindex_required', 'reason' => 'first_start'], $response, ignore: ['request_id']);
        $this->assertSame('10', $response->headers['Retry-After'] ?? null);

        $this->assertResponse(200, ['generation' => 1, 'cancelled_generation' => null], $this->call('POST', '/reindex'));
        $this->assertResponse(200, ['generation' => 1, 'removed' => 0], $this->call('POST', '/reindex/1/complete'));
        $this->assertResponse(200, ['status' => 'ready'], $this->call('GET', '/health/ready', token: null));
    }

    public function testTheWholeDocumentLifecycle(): void
    {
        $this->ready();

        $this->assertResponse(
            200,
            ['id' => 'SKU-1', 'applied' => true, 'source_version' => 3, 'generation' => null],
            $this->call('PUT', '/documents/SKU-1', ['source_version' => 3, 'title' => 'Кабель USB-C', 'body' => 'Быстрая зарядка']),
        );
        $this->assertResponse(
            200,
            ['id' => 'SKU-1', 'applied' => false, 'source_version' => 3, 'generation' => null],
            $this->call('PUT', '/documents/SKU-1', ['source_version' => 2, 'title' => 'old', 'body' => '']),
        );

        $search = $this->decode($this->call('POST', '/search', ['query' => 'кабели usb', 'limit' => 5]));
        $this->assertSame(1, $search['total']);
        $this->assertSame(5, $search['limit']);
        $this->assertSame(0, $search['offset']);
        $this->assertIsArray($search['results']);
        $this->assertSame(['id' => 'SKU-1', 'source_version' => 3], array_diff_key((array) $search['results'][0], ['score' => 0]));

        $this->assertResponse(200, ['id' => 'SKU-1', 'applied' => true, 'source_version' => 4], $this->call('DELETE', '/documents/SKU-1?source_version=4'));
        $this->assertResponse(200, ['id' => 'SKU-1', 'state' => 'deleted', 'source_version' => 4], $this->call('GET', '/documents/SKU-1'));
        $this->assertSame(0, $this->decode($this->call('POST', '/search', ['query' => 'кабель']))['total']);
    }

    public function testDeletingAnUnknownIdLeavesATombstone(): void
    {
        $this->assertResponse(200, ['id' => 'never', 'applied' => true, 'source_version' => 1], $this->call('DELETE', '/documents/never?source_version=1'));
        $this->assertSame('deleted', $this->decode($this->call('GET', '/documents/never'))['state']);
    }

    public function testEveryResponseCarriesARequestIdAndTakesTheClients(): void
    {
        $mine = $this->call('GET', '/health/live', token: null, headers: ['x-request-id' => 'platform-42']);
        $generated = $this->call('GET', '/nowhere');

        $this->assertSame('platform-42', $mine->headers['X-Request-Id'] ?? null);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $generated->headers['X-Request-Id'] ?? '');
        $this->assertSame($generated->headers['X-Request-Id'], $this->decode($generated)['request_id']);
    }

    /**
     * @return iterable<string, array{string, string, array<mixed>|string|null, int, string, ?string}>
     */
    public static function errors(): iterable
    {
        yield 'no token' => ['GET', '/stats', null, 401, 'unauthorized', 'none'];
        yield 'wrong token' => ['GET', '/stats', null, 401, 'unauthorized', 'wrong'];
        yield 'unknown path' => ['GET', '/nowhere', null, 404, 'route_not_found', null];
        yield 'unknown document' => ['GET', '/documents/missing', null, 404, 'document_not_found', null];
        yield 'wrong method' => ['PATCH', '/search', null, 405, 'method_not_allowed', null];
        yield 'not JSON' => ['POST', '/search', '{"query":', 400, 'invalid_json', null];
        yield 'a JSON list' => ['POST', '/search', '["a"]', 400, 'invalid_json', null];
        yield 'empty body' => ['PUT', '/documents/a', '', 400, 'invalid_json', null];
        yield 'bad id' => ['GET', '/documents/no%20spaces', null, 422, 'validation_failed', null];
        yield 'missing source_version' => ['PUT', '/documents/a', ['title' => 'x', 'body' => 'y'], 422, 'validation_failed', null];
        yield 'zero source_version' => ['PUT', '/documents/a', ['source_version' => 0, 'title' => 'x', 'body' => 'y'], 422, 'validation_failed', null];
        yield 'float source_version' => ['PUT', '/documents/a', ['source_version' => 1.5, 'title' => 'x', 'body' => 'y'], 422, 'validation_failed', null];
        yield 'title too long' => ['PUT', '/documents/a', ['source_version' => 1, 'title' => str_repeat('я', 513), 'body' => ''], 422, 'validation_failed', null];
        yield 'unknown field' => ['PUT', '/documents/a', ['source_version' => 1, 'title' => '', 'body' => '', 'metadata' => []], 422, 'validation_failed', null];
        yield 'delete without a version' => ['DELETE', '/documents/a', null, 422, 'validation_failed', null];
        yield 'limit over 100' => ['POST', '/search', ['query' => 'x', 'limit' => 101], 422, 'validation_failed', null];
        yield 'too deep' => ['POST', '/search', ['query' => 'x', 'limit' => 100, 'offset' => 901], 422, 'offset_too_deep', null];
        yield 'complete a generation that is not open' => ['POST', '/reindex/7/complete', null, 409, 'generation_not_open', null];
        yield 'search before any reindex' => ['POST', '/search', ['query' => 'x'], 503, 'reindex_required', null];
    }

    /**
     * @param array<mixed>|string|null $body
     */
    #[DataProvider('errors')]
    public function testErrorsHaveOneShape(string $method, string $path, array|string|null $body, int $status, string $code, ?string $token): void
    {
        $response = $this->call($method, $path, $body, token: match ($token) {
            'none' => null,
            'wrong' => str_repeat('x', 32),
            default => self::TOKEN,
        });

        $this->assertSame($status, $response->status);
        $data = $this->decode($response);
        $this->assertIsArray($data['error']);
        $this->assertSame($code, $data['error']['code']);
        $this->assertIsString($data['error']['message']);
        $this->assertIsString($data['request_id']);
    }

    public function testQueryErrorsAfterAReindex(): void
    {
        $this->ready();

        $this->assertSame('empty_query', $this->errorCode($this->call('POST', '/search', ['query' => '!!!'])));
        $this->assertSame('too_many_terms', $this->errorCode($this->call('POST', '/search', ['query' => implode(' ', array_map(fn ($i) => "w{$i}", range(1, 33)))])));
    }

    public function testAWrongMethodNamesTheAllowedOnes(): void
    {
        $this->assertSame('PUT, DELETE, GET', $this->call('POST', '/documents/a')->headers['Allow'] ?? null);
    }

    public function testAJsonBodyNeedsTheJsonContentType(): void
    {
        $response = $this->call('POST', '/search', ['query' => 'x'], headers: ['content-type' => 'text/plain']);

        $this->assertSame(415, $response->status);
        $this->assertSame('unsupported_media_type', $this->errorCode($response));
    }

    public function testATokenIsCheckedBeforeTheRoute(): void
    {
        $this->assertSame(401, $this->call('GET', '/nowhere', token: null)->status);
    }

    public function testFramingErrorsFromTheServerGetTheSameShape(): void
    {
        $response = $this->api->reject(new HttpError(431, 'headers_too_large', 'too large'));

        $this->assertSame(431, $response->status);
        $this->assertSame('headers_too_large', $this->errorCode($response));
    }

    public function testStatsDescribeTheCorpusAndTheRequests(): void
    {
        $this->ready();
        $this->call('PUT', '/documents/a', ['source_version' => 1, 'title' => 'кабель', 'body' => '']);

        $stats = $this->decode($this->call('GET', '/stats'));

        $this->assertSame(1, $stats['documents']);
        $this->assertSame(1, $stats['last_completed_generation']);
        $this->assertIsArray($stats['requests']);
        $this->assertSame(1, $stats['requests']['PUT /documents/{id} 200']);
        $this->assertIsArray($stats['latency_ms']);
        $this->assertGreaterThan(0, $stats['rss_bytes']);
    }

    private function ready(): void
    {
        $this->call('POST', '/reindex');
        $this->call('POST', '/reindex/1/complete');
    }

    /**
     * @param array<mixed>|string|null $body
     * @param array<string, string> $headers
     */
    private function call(string $method, string $target, array|string|null $body = null, ?string $token = self::TOKEN, array $headers = []): Response
    {
        if ($token !== null) {
            $headers['authorization'] ??= 'Bearer ' . $token;
        }
        if ($body !== null) {
            $headers['content-type'] ??= 'application/json';
        }
        $raw = is_array($body) ? (string) json_encode($body) : ($body ?? '');

        return $this->api->handle(new Request($method, $target, '1.1', $headers + ['host' => 'x'], $raw));
    }

    /**
     * @param array<string, mixed> $expected
     * @param list<string> $ignore
     */
    private function assertResponse(int $status, array $expected, Response $response, array $ignore = []): void
    {
        $this->assertSame($status, $response->status, $response->body);
        $this->assertSame($expected, array_diff_key($this->decode($response), array_flip($ignore)));
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(Response $response): array
    {
        $data = json_decode($response->body, true);
        $this->assertIsArray($data, $response->body);

        /** @var array<string, mixed> $data */
        return $data;
    }

    private function errorCode(Response $response): mixed
    {
        $error = $this->decode($response)['error'] ?? null;
        $this->assertIsArray($error);

        return $error['code'] ?? null;
    }
}
