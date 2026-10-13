<?php

declare(strict_types=1);

namespace PhpMiniSearch\Tests\Http;

use PhpMiniSearch\Http\HttpError;
use PhpMiniSearch\Http\Request;
use PhpMiniSearch\Http\RequestParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The parser against a corpus of valid and malformed requests. Every one is
 * delivered three ways — whole, one byte at a time, and in random chunks —
 * and must give the same result each time: TCP decides how bytes are split,
 * and the parser must not care.
 */
final class RequestParserTest extends TestCase
{
    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function corpus(): iterable
    {
        $ok = static fn (string $method, string $target, string $body = '') => "{$method} {$target} {$body}";

        yield 'GET' => ["GET /health/live HTTP/1.1\r\nHost: x\r\n\r\n", [$ok('GET', '/health/live')]];
        yield 'PUT with a body' => ["PUT /documents/a HTTP/1.1\r\nHost: x\r\nContent-Length: 5\r\n\r\nhello", [$ok('PUT', '/documents/a', 'hello')]];
        yield 'two pipelined requests' => [
            "GET /a HTTP/1.1\r\nHost: x\r\n\r\nPOST /b HTTP/1.1\r\nHost: x\r\nContent-Length: 2\r\n\r\nhi",
            [$ok('GET', '/a'), $ok('POST', '/b', 'hi')],
        ];
        yield 'a stray CRLF before the request line' => ["\r\nGET /a HTTP/1.1\r\nHost: x\r\n\r\n", [$ok('GET', '/a')]];
        yield 'HTTP/1.0 without Host' => ["GET /a HTTP/1.0\r\n\r\n", [$ok('GET', '/a')]];
        yield 'whitespace around a header value' => ["POST /a HTTP/1.1\r\nHost: x\r\nContent-Length:  2 \r\n\r\nhi", [$ok('POST', '/a', 'hi')]];
        yield 'a UTF-8 body' => ["PUT /a HTTP/1.1\r\nHost: x\r\nContent-Length: 12\r\n\r\nкабель", [$ok('PUT', '/a', 'кабель')]];

        yield 'bare LF line endings' => ["GET /a HTTP/1.1\nHost: x\n\n", []];
        yield 'bare LF inside the head' => ["GET /a HTTP/1.1\r\nHost: x\nX: y\r\n\r\n", ['400 malformed_request']];
        yield 'a NUL byte' => ["GET /a HTTP/1.1\r\nHost: x\0\r\n\r\n", ['400 malformed_request']];
        yield 'two spaces in the request line' => ["GET  /a HTTP/1.1\r\nHost: x\r\n\r\n", ['400 malformed_request']];
        yield 'absolute-form target' => ["GET http://x/a HTTP/1.1\r\nHost: x\r\n\r\n", ['400 malformed_request']];
        yield 'HTTP/2.0' => ["GET /a HTTP/2.0\r\nHost: x\r\n\r\n", ['400 malformed_request']];
        yield 'a method that is not a token' => ["G(T /a HTTP/1.1\r\nHost: x\r\n\r\n", ['400 malformed_request']];
        yield 'HTTP/1.1 without Host' => ["GET /a HTTP/1.1\r\n\r\n", ['400 malformed_request']];
        yield 'a folded header' => ["GET /a HTTP/1.1\r\nHost: x\r\n continued\r\n\r\n", ['400 malformed_request']];
        yield 'whitespace before the colon' => ["GET /a HTTP/1.1\r\nHost : x\r\n\r\n", ['400 malformed_request']];
        yield 'a header without a colon' => ["GET /a HTTP/1.1\r\nHost x\r\n\r\n", ['400 malformed_request']];
        yield 'Transfer-Encoding' => ["POST /a HTTP/1.1\r\nHost: x\r\nTransfer-Encoding: chunked\r\n\r\n2\r\nhi\r\n0\r\n\r\n", ['501 unsupported_transfer_encoding']];
        yield 'Content-Length and Transfer-Encoding' => ["POST /a HTTP/1.1\r\nHost: x\r\nContent-Length: 2\r\nTransfer-Encoding: chunked\r\n\r\nhi", ['501 unsupported_transfer_encoding']];
        yield 'two equal Content-Lengths' => ["POST /a HTTP/1.1\r\nHost: x\r\nContent-Length: 2\r\nContent-Length: 2\r\n\r\nhi", ['400 malformed_request']];
        yield 'two different Content-Lengths' => ["POST /a HTTP/1.1\r\nHost: x\r\nContent-Length: 2\r\nContent-Length: 3\r\n\r\nhi!", ['400 malformed_request']];
        yield 'a signed Content-Length' => ["POST /a HTTP/1.1\r\nHost: x\r\nContent-Length: +2\r\n\r\nhi", ['400 malformed_request']];
        yield 'a list as Content-Length' => ["POST /a HTTP/1.1\r\nHost: x\r\nContent-Length: 2, 2\r\n\r\nhi", ['400 malformed_request']];
        yield 'POST without Content-Length has an empty body' => ["POST /a HTTP/1.1\r\nHost: x\r\n\r\n", [$ok('POST', '/a')]];
        yield 'a body over the limit' => ["PUT /a HTTP/1.1\r\nHost: x\r\nContent-Length: 1001\r\n\r\n", ['413 payload_too_large']];
        yield 'a head over the limit' => ["GET /a HTTP/1.1\r\nHost: x\r\nX: " . str_repeat('a', 600) . "\r\n\r\n", ['431 headers_too_large']];
        yield 'an endless head' => ['GET /' . str_repeat('a', 700), ['431 headers_too_large']];
        yield 'nothing after an error' => ["GET /a HTTP/1.1\r\n\r\nGET /b HTTP/1.1\r\nHost: x\r\n\r\n", ['400 malformed_request']];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('corpus')]
    public function testTheResultDoesNotDependOnHowTheBytesArrive(string $raw, array $expected): void
    {
        $this->assertSame($expected, $this->parse([$raw]), 'whole');
        $this->assertSame($expected, $this->parse(str_split($raw)), 'byte by byte');

        mt_srand(crc32($raw));
        for ($round = 0; $round < 5; $round++) {
            $chunks = [];
            for ($offset = 0; $offset < strlen($raw); $offset += $size) {
                $size = mt_rand(1, 20);
                $chunks[] = substr($raw, $offset, $size);
            }
            $this->assertSame($expected, $this->parse($chunks), "random chunks, round {$round}");
        }
    }

    public function testExpectContinueIsOfferedOnceBeforeTheBody(): void
    {
        $parser = new RequestParser();
        $parser->feed("PUT /a HTTP/1.1\r\nHost: x\r\nExpect: 100-continue\r\nContent-Length: 2\r\n\r\n");

        $this->assertNull($parser->next());
        $this->assertTrue($parser->takeContinue());
        $this->assertFalse($parser->takeContinue());

        $parser->feed('hi');
        $request = $parser->next();
        $this->assertInstanceOf(Request::class, $request);
        $this->assertSame('hi', $request->body);
    }

    public function testExpectContinueIsNotOfferedWhenTheBodyIsAlreadyHere(): void
    {
        $parser = new RequestParser();
        $parser->feed("PUT /a HTTP/1.1\r\nHost: x\r\nExpect: 100-continue\r\nContent-Length: 2\r\n\r\nhi");

        $this->assertInstanceOf(Request::class, $parser->next());
        $this->assertFalse($parser->takeContinue());
    }

    public function testRepeatedHeadersAreJoinedAndNamesAreCaseInsensitive(): void
    {
        $parser = new RequestParser();
        $parser->feed("GET /a?x=1 HTTP/1.1\r\nHOST: x\r\nAccept: a\r\naccept: b\r\n\r\n");
        $request = $parser->next();

        $this->assertInstanceOf(Request::class, $request);
        $this->assertSame('a, b', $request->header('Accept'));
        $this->assertSame('/a', $request->path());
        $this->assertSame(['x' => '1'], $request->query());
    }

    /**
     * Feeds the chunks and collects everything the parser produces, as
     * "METHOD target body" for requests and "status code" for errors.
     *
     * @param list<string> $chunks
     * @return list<string>
     */
    private function parse(array $chunks): array
    {
        $parser = new RequestParser(maxHeadBytes: 512, maxBodyBytes: 1_000);
        $results = [];

        foreach ($chunks as $chunk) {
            $parser->feed($chunk);
            while (($next = $parser->next()) !== null) {
                if ($next instanceof HttpError) {
                    $results[] = "{$next->status} {$next->code}";
                    break 2;
                }
                $results[] = "{$next->method} {$next->target} {$next->body}";
            }
        }

        return $results;
    }
}
