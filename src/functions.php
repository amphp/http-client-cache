<?php declare(strict_types=1);

namespace Amp\Http\Client\Cache;

use Amp\Http\HttpMessage;
use function Amp\Http\createFieldValueComponentMap;
use function Amp\Http\parseFieldValueComponents;

/**
 * @see https://tools.ietf.org/html/rfc7234.html#section-1.2.1
 */
function parseDeltaSeconds(?string $value): ?int
{
    if ($value === null) {
        return null;
    }

    $filterResult = \filter_var(
        $value,
        \FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 0, 'max_range' => \PHP_INT_MAX]]
    );

    if ($filterResult !== false) {
        return (int) $value;
    }

    /** @noinspection NotOptimalRegularExpressionsInspection */
    if (\preg_match('/^[1-9][0-9]+$/', $value)) {
        return \PHP_INT_MAX;
    }

    return null;
}

function parseExpiresHeader(string $value): \DateTimeImmutable
{
    static $dateInThePast;

    if ($dateInThePast === null) {
        /** @noinspection PhpUnhandledExceptionInspection */
        $dateInThePast = new \DateTimeImmutable('@0', new \DateTimeZone('UTC'));
    }

    $timezone = \substr($value, -4);
    $isValidTimezone = $timezone === ' UTC' || $timezone === ' GMT';

    if ($value === '0' || !$isValidTimezone) {
        return $dateInThePast;
    }

    return \DateTimeImmutable::createFromFormat('D, d M Y H:i:s e', $value, new \DateTimeZone('UTC')) ?: $dateInThePast;
}

function parseDateHeader(?string $value): ?\DateTimeImmutable
{
    if ($value === null) {
        return null;
    }

    $timezone = \substr($value, -4);
    $isValidTimezone = $timezone === ' UTC' || $timezone === ' GMT';

    if (!$isValidTimezone) {
        return null;
    }

    return \DateTimeImmutable::createFromFormat('D, d M Y H:i:s e', $value, new \DateTimeZone('UTC')) ?: null;
}

function parseCacheControlHeader(HttpMessage $message): array
{
    // Only fallback to pragma if no header is present
    // See https://tools.ietf.org/html/rfc7234.html#section-5.4
    $header = $message->hasHeader('cache-control') ? 'cache-control' : 'pragma';
    $parsedComponents = createFieldValueComponentMap(parseFieldValueComponents($message, $header));

    if ($parsedComponents === null) {
        return ['no-store' => true];
    }

    $cacheControl = [];

    foreach ($parsedComponents as $key => $value) {
        $deltaSecondAttributes = ['max-age', 'max-stale', 'min-fresh', 's-maxage'];

        $tokenOnlyAttributes = [
            'no-cache',
            'no-store',
            'no-transform',
            'only-if-cached',
            'must-revalidate',
            'public',
            'private',
            'proxy-revalidate',
        ];

        if (\in_array($key, $deltaSecondAttributes, true)) {
            if ($key === 'max-stale' && $value === '') {
                $value = \PHP_INT_MAX;
            } else {
                $value = parseDeltaSeconds($value) ?? 0;
            }
        } elseif (\in_array($key, $tokenOnlyAttributes, true)) {
            // no or invalid value given, ignore that fact
            $value = true;
        } else {
            continue; // no or unknown key given, ignore token
        }

        $cacheControl[$key] = $value;
    }

    return $cacheControl;
}

function now(): \DateTimeImmutable
{
    /** @noinspection PhpUnhandledExceptionInspection */
    return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
}
