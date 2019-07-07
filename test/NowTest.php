<?php

namespace Amp\Http\Client\Cache;

use PHPUnit\Framework\TestCase;

class NowTest extends TestCase
{
    public function test(): void
    {
        self::assertSame('UTC', now()->getTimezone()->getName());
    }
}
