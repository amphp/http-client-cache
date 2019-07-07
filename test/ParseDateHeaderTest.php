<?php

namespace Amp\Http\Client\Cache;

use PHPUnit\Framework\TestCase;

class ParseDateHeaderTest extends TestCase
{
    public function test(): void
    {
        self::assertSame(786269551, parseDateHeader('Thu, 01 Dec 1994 08:12:31 GMT')->getTimestamp());
    }
}