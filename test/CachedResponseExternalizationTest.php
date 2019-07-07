<?php /** @noinspection PhpUnhandledExceptionInspection */

namespace Amp\Http\Client\Cache;

use Amp\ByteStream\InMemoryStream;
use Amp\Http\Client\ConnectionInfo;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use Amp\Socket\SocketAddress;
use PHPUnit\Framework\TestCase;

class CachedResponseExternalizationTest extends TestCase
{
    public function test(): void
    {
        $connectionInfo = new ConnectionInfo(new SocketAddress(''), new SocketAddress(''));
        $request = new Request('https://example.org/');
        $response = new Response('1.1', 200, 'OK', ['date' => 'test'], new InMemoryStream, $request, $connectionInfo);

        $cachedResponse = CachedResponse::fromResponse($response, now(), now(), 'abc');

        $dump = $cachedResponse->toCacheData();

        self::assertSame($dump, CachedResponse::fromCacheData($dump)->toCacheData());
    }

    public function testVary(): void
    {
        $connectionInfo = new ConnectionInfo(new SocketAddress(''), new SocketAddress(''));
        $request = new Request('https://example.org/');
        $request = $request->withHeader('accept', 'foobar');
        $response = new Response('1.1', 200, 'OK', ['vary' => 'accept'], new InMemoryStream, $request, $connectionInfo);

        $cachedResponse = CachedResponse::fromResponse($response, now(), now(), 'abc');

        $dump = $cachedResponse->toCacheData();

        self::assertSame($dump, CachedResponse::fromCacheData($dump)->toCacheData());
    }
}
