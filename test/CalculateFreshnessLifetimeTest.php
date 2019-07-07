<?php

namespace Amp\Http\Client\Cache;

use Amp\ByteStream\InMemoryStream;
use Amp\Http\Client\ConnectionInfo;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use Amp\Socket\SocketAddress;
use PHPUnit\Framework\TestCase;

class CalculateFreshnessLifetimeTest extends TestCase
{
    private $headers = [];
    private $result;

    public function testMaxAge(): void
    {
        $this->givenHeader('cache-control', 'public, max-age=30');

        $this->whenFreshnessLifetimeIsCalculated();

        $this->thenFreshnessLifetimeIsEqualTo(30);
    }

    public function testExpiresNow(): void
    {
        $this->givenHeader('expires', \gmdate('D, d M Y H:i:s') . ' GMT');

        $this->whenFreshnessLifetimeIsCalculated();

        $this->thenFreshnessLifetimeIsEqualTo(0);
    }

    public function testExpiresFuture(): void
    {
        $this->givenHeader('expires', \gmdate('D, d M Y H:i:s', \time() + 30) . ' GMT');

        $this->whenFreshnessLifetimeIsCalculated();

        $this->thenFreshnessLifetimeIsBetween(30, 31);
    }

    public function testExpiresPast(): void
    {
        $this->givenHeader('expires', \gmdate('D, d M Y H:i:s', \time() - 30) . ' GMT');

        $this->whenFreshnessLifetimeIsCalculated();

        $this->thenFreshnessLifetimeIsEqualTo(0);
    }

    protected function setUp(): void
    {
        $this->givenHeader('date', \gmdate('D, d M Y H:i:s') . ' GMT');
    }

    protected function givenHeader(string $field, string $value): void
    {
        $this->headers[$field] = [$value];
    }

    protected function whenFreshnessLifetimeIsCalculated(): void
    {
        $connectionInfo = new ConnectionInfo(new SocketAddress(''), new SocketAddress(''));
        $request = new Request('https://example.org/');
        $response = new Response('1.1', 200, 'OK', $this->headers, new InMemoryStream, $request, $connectionInfo);

        $cachedResponse = CachedResponse::fromResponse($response, now(), now(), 'abc');

        $this->result = calculateFreshnessLifetime($cachedResponse);
    }

    protected function thenFreshnessLifetimeIsEqualTo(int $freshnessLifetime): void
    {
        self::assertSame($freshnessLifetime, $this->result);
    }

    protected function thenFreshnessLifetimeIsBetween(int $lower, int $upper): void
    {
        self::assertGreaterThanOrEqual($lower, $this->result);
        self::assertLessThanOrEqual($upper, $this->result);
    }
}