<?php

declare(strict_types=1);

namespace PhpMiniSearch\Tests\Http;

use PhpMiniSearch\Http\ServerConfig;
use PhpMiniSearch\Tests\Support\EchoHandler;
use PhpMiniSearch\Tests\Support\HttpHarness;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The server on real sockets. The connection lifecycle, every limit and
 * every timeout from docs/ARCHITECTURE.md — timeouts by moving the
 * harness's clock, not by sleeping.
 */
final class ServerTest extends TestCase
{
    private HttpHarness $http;

    protected function setUp(): void
    {
        $this->http = new HttpHarness(new EchoHandler(), new ServerConfig(
            maxConnections: 3,
            maxHeadBytes: 1_024,
            maxBodyBytes: 4_096,
        ));
    }

    protected function tearDown(): void
    {
        $this->http->close();
    }

    public function testARequestGetsAResponseWithAnExactContentLength(): void
    {
        $client = $this->http->connect();
        $this->http->send($client, "PUT /documents/a HTTP/1.1\r\nHost: x\r\nContent-Length: 12\r\n\r\nкабель");

        $response = $this->http->receive($client);

        $this->assertNotNull($response);
        $this->assertSame(200, $response['status']);
        $this->assertSame((string) strlen($response['body']), $response['headers']['content-length']);
        $this->assertSame('кабель', $this->decode($response['body'])['body']);
    }

    public function testKeepAliveServesSeveralRequestsOnOneConnection(): void
    {
        $client = $this->http->connect();

        foreach (['/a', '/b', '/c'] as $path) {
            $this->http->send($client, "GET {$path} HTTP/1.1\r\nHost: x\r\n\r\n");
            $response = $this->http->receive($client);
            $this->assertNotNull($response);
            $this->assertSame($path, $this->decode($response['body'])['target']);
            $this->assertArrayNotHasKey('connection', $response['headers']);
        }
    }

    public function testPipelinedRequestsAreAnsweredInOrder(): void
    {
        $client = $this->http->connect();
        $this->http->send(
            $client,
            "GET /1 HTTP/1.1\r\nHost: x\r\n\r\nPOST /2 HTTP/1.1\r\nHost: x\r\nContent-Length: 3\r\n\r\ntwoGET /3 HTTP/1.1\r\nHost: x\r\n\r\n",
        );

        $targets = [];
        for ($i = 0; $i < 3; $i++) {
            $response = $this->http->receive($client);
            $this->assertNotNull($response);
            $targets[] = $this->decode($response['body'])['target'];
        }

        $this->assertSame(['/1', '/2', '/3'], $targets);
    }

    public function testConnectionCloseIsHonoured(): void
    {
        $client = $this->http->connect();
        $this->http->send($client, "GET /a HTTP/1.1\r\nHost: x\r\nConnection: close\r\n\r\n");

        $this->assertSame('close', $this->http->receive($client)['headers']['connection'] ?? null);
        $this->assertTrue($this->http->isClosed($client));
    }

    public function testHttp10ClosesUnlessAskedToKeepAlive(): void
    {
        $closing = $this->http->connect();
        $this->http->send($closing, "GET /a HTTP/1.0\r\n\r\n");
        $this->assertSame('close', $this->http->receive($closing)['headers']['connection'] ?? null);
        $this->assertTrue($this->http->isClosed($closing));

        $keeping = $this->http->connect();
        $this->http->send($keeping, "GET /a HTTP/1.0\r\nConnection: keep-alive\r\n\r\n");
        $this->assertSame('keep-alive', $this->http->receive($keeping)['headers']['connection'] ?? null);
        $this->assertFalse($this->http->isClosed($keeping));
    }

    /**
     * @return iterable<string, array{string, int, string}>
     */
    public static function rejected(): iterable
    {
        yield 'malformed' => ["GET /a HTTP/1.1\r\n\r\n", 400, 'malformed_request'];
        yield 'chunked' => ["POST /a HTTP/1.1\r\nHost: x\r\nTransfer-Encoding: chunked\r\n\r\n0\r\n\r\n", 501, 'unsupported_transfer_encoding'];
        yield 'no length' => ["PUT /a HTTP/1.1\r\nHost: x\r\n\r\n", 411, 'length_required'];
        yield 'too large' => ["PUT /a HTTP/1.1\r\nHost: x\r\nContent-Length: 5000\r\n\r\n", 413, 'payload_too_large'];
        yield 'head too large' => ["GET /a HTTP/1.1\r\nHost: x\r\nX: " . str_repeat('a', 1_100) . "\r\n\r\n", 431, 'headers_too_large'];
    }

    #[DataProvider('rejected')]
    public function testARejectedRequestGetsItsErrorAndTheConnectionCloses(string $raw, int $status, string $code): void
    {
        foreach (['whole' => [$raw], 'byte by byte' => str_split($raw)] as $how => $chunks) {
            $client = $this->http->connect();
            foreach ($chunks as $chunk) {
                $this->http->send($client, $chunk);
            }

            $response = $this->http->receive($client);
            $this->assertNotNull($response, $how);
            $this->assertSame($status, $response['status'], $how);
            $this->assertSame($code, $this->errorCode($response['body']), $how);
            $this->assertSame('close', $response['headers']['connection'] ?? null, $how);
            $this->assertTrue($this->http->isClosed($client), $how);
        }
    }

    public function testExpectContinueGetsAGoAheadBeforeTheBody(): void
    {
        $client = $this->http->connect();
        $this->http->send($client, "PUT /a HTTP/1.1\r\nHost: x\r\nExpect: 100-continue\r\nContent-Length: 2\r\n\r\n");

        $this->assertSame(100, $this->http->receive($client)['status'] ?? null);

        $this->http->send($client, 'hi');
        $this->assertSame('hi', $this->decode($this->http->receive($client)['body'] ?? '')['body']);
    }

    public function testExpectContinueWithAnOversizedBodyIsRefusedBeforeTheBodyIsSent(): void
    {
        $client = $this->http->connect();
        $this->http->send($client, "PUT /a HTTP/1.1\r\nHost: x\r\nExpect: 100-continue\r\nContent-Length: 5000\r\n\r\n");

        $this->assertSame(413, $this->http->receive($client)['status'] ?? null);
    }

    public function testAHeadRequestGetsHeadersButNoBody(): void
    {
        $client = $this->http->connect();
        $this->http->send($client, "HEAD /a HTTP/1.1\r\nHost: x\r\n\r\nGET /b HTTP/1.1\r\nHost: x\r\n\r\n");

        $head = $this->http->receive($client, toHead: true);
        $this->assertNotNull($head);
        $this->assertNotSame('0', $head['headers']['content-length']);
        // Had HEAD's body been sent, it would sit in front of the next response.
        $this->assertSame('/b', $this->decode($this->http->receive($client)['body'] ?? '')['target']);
    }

    public function testAHandlerThatThrowsBecomesA500(): void
    {
        $client = $this->http->connect();
        $this->http->send($client, "GET /boom HTTP/1.1\r\nHost: x\r\n\r\n");

        $response = $this->http->receive($client);

        $this->assertNotNull($response);
        $this->assertSame(500, $response['status']);
        $this->assertSame('internal_error', $this->errorCode($response['body']));
    }

    public function testASlowHeadIsDroppedAfterTheHeadTimeout(): void
    {
        $client = $this->http->connect();
        $this->http->send($client, "GET /a HTTP/1.1\r\nHo");

        $this->http->now += 9.9;
        $this->assertFalse($this->http->isClosed($client));
        $this->http->now += 0.2;
        $this->assertTrue($this->http->isClosed($client));
    }

    public function testASlowBodyIsDroppedAfterTheBodyTimeout(): void
    {
        $client = $this->http->connect();
        $this->http->send($client, "PUT /a HTTP/1.1\r\nHost: x\r\nContent-Length: 10\r\n\r\nhal");

        $this->http->now += 29.9;
        $this->assertFalse($this->http->isClosed($client));
        $this->http->now += 0.2;
        $this->assertTrue($this->http->isClosed($client));
    }

    public function testAnIdleKeepAliveConnectionIsClosed(): void
    {
        $client = $this->http->connect();
        $this->http->send($client, "GET /a HTTP/1.1\r\nHost: x\r\n\r\n");
        $this->http->receive($client);

        $this->http->now += 29.9;
        $this->assertFalse($this->http->isClosed($client));
        $this->http->now += 0.2;
        $this->assertTrue($this->http->isClosed($client));
    }

    public function testAClientThatStopsReadingIsDroppedAfterTheWriteTimeout(): void
    {
        $client = $this->http->connect();
        // Far more than the socket buffers hold, so the write cannot finish while nobody reads.
        $this->http->send($client, "GET /big?bytes=50000000 HTTP/1.1\r\nHost: x\r\n\r\n");
        $this->http->tick(20);
        $this->assertSame(1, $this->http->server->connectionCount());

        $this->http->now += 30.1;
        $this->http->tick(2);

        $this->assertSame(0, $this->http->server->connectionCount());
    }

    public function testBeyondTheConnectionCapNewClientsWait(): void
    {
        $clients = [$this->http->connect(), $this->http->connect(), $this->http->connect()];
        $waiting = $this->http->connect();
        $this->assertSame(3, $this->http->server->connectionCount());

        fclose($clients[0]);
        $this->http->tick(5);
        $this->http->send($waiting, "GET /late HTTP/1.1\r\nHost: x\r\n\r\n");

        $this->assertSame('/late', $this->decode($this->http->receive($waiting)['body'] ?? '')['target']);
    }

    private function errorCode(string $json): mixed
    {
        $error = $this->decode($json)['error'] ?? null;
        $this->assertIsArray($error);

        return $error['code'] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $json): array
    {
        $data = json_decode($json, true);
        $this->assertIsArray($data);

        /** @var array<string, mixed> $data */
        return $data;
    }
}
