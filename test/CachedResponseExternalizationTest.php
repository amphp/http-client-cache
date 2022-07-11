<?php

namespace Amp\Http\Client\Cache;

use Amp\ByteStream\ReadableBuffer;
use Amp\Http\Client\Cache\Internal\CachedResponse;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use PHPUnit\Framework\TestCase;

class CachedResponseExternalizationTest extends TestCase
{
    public function test(): void
    {
        $request = new Request('https://example.org/');
        $response = new Response('1.1', 200, 'OK', ['date' => 'test'], new ReadableBuffer(), $request);

        $cachedResponse = CachedResponse::fromResponse($request, $response, now(), now(), 'abc');

        $dump = $cachedResponse->toCacheData();

        self::assertSame($dump, CachedResponse::fromCacheData($dump)->toCacheData());
    }

    public function testVary(): void
    {
        $request = new Request('https://example.org/');
        $request->setHeader('accept', 'foobar');
        $response = new Response('1.1', 200, 'OK', ['vary' => 'accept'], new ReadableBuffer(), $request);

        $cachedResponse = CachedResponse::fromResponse($request, $response, now(), now(), 'abc');

        $dump = $cachedResponse->toCacheData();

        self::assertSame($dump, CachedResponse::fromCacheData($dump)->toCacheData());
    }
}
