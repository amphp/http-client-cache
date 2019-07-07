<?php

namespace Amp\Http\Client\Cache;

use Amp\Http\Message;
use PHPUnit\Framework\TestCase;

class ParseCacheControlHeaderTest extends TestCase
{
    public function test(): void
    {
        self::assertSame([
            'no-cache' => true,
            'no-store' => true,
            'must-revalidate' => true,
        ], $this->parse('no-cache, no-store, must-revalidate'));

        self::assertSame([
            'public' => true,
            'max-age' => 31536000,
        ], $this->parse('public, max-age=31536000'));

        self::assertSame([
            'private' => true,
            'max-age' => 31536000,
        ], $this->parse('private="foo, bar", max-age=31536000'));

        self::assertSame([
            'private' => true,
            'max-stale' => \PHP_INT_MAX,
        ], $this->parse('private, max-stale'));

        self::assertSame([], $this->parse('foobar'));

        self::assertSame(['no-store' => true], $this->parse('foobar=1, foobar=2'));
    }

    private function parse(string $headerValue): array
    {
        return parseCacheControlHeader(new class(['cache-control' => $headerValue]) extends Message {
            public function __construct(array $headers = [])
            {
                /** @noinspection PhpUnhandledExceptionInspection */
                $this->setHeaders($headers);
            }
        });
    }
}
