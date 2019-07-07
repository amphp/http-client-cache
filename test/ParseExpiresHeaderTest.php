<?php

namespace Amp\Http\Client\Cache;

use PHPUnit\Framework\TestCase;

class ParseExpiresHeaderTest extends TestCase
{
    public function test(): void
    {
        self::assertSame(0, parseExpiresHeader('foobar')->getTimestamp());
        self::assertSame(0, parseExpiresHeader('0')->getTimestamp());
        self::assertSame(786269551, parseExpiresHeader('Thu, 01 Dec 1994 08:12:31 GMT')->getTimestamp());
        self::assertSame(0, parseExpiresHeader('Thu, 01 Dec 1994 08:12:31 CET')->getTimestamp());
    }
}
