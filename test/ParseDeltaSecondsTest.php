<?php

namespace Amp\Http\Client\Cache;

use PHPUnit\Framework\TestCase;

class ParseDeltaSecondsTest extends TestCase
{
    public function testValid(): void
    {
        self::assertSame(0, parseDeltaSeconds('0'));
        self::assertSame(\PHP_INT_MAX, parseDeltaSeconds('9223372036854775807'));
    }

    public function testNegative(): void
    {
        self::assertNull(parseDeltaSeconds('-1'));
    }

    public function testOverflow(): void
    {
        self::assertSame(\PHP_INT_MAX, parseDeltaSeconds('9223372036854775808'));
    }

    public function testNull(): void
    {
        self::assertNull(parseDeltaSeconds(null));
    }
}
