<?php

namespace Amp\Http\Client\Cache;

use Amp\Http\Client\Request;
use Amp\Http\Message;
use function Amp\Http\createFieldValueComponentMap;
use function Amp\Http\parseFieldValueComponents;
use Amp\Socket\SocketAddress;

/**
 * @param string|null $value
 *
 * @return int|null
 *
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

function parseCacheControlHeader(Message $message): array
{
    // Only fallback to pragma if no header is present
    // See https://tools.ietf.org/html/rfc7234.html#section-5.4
    $header = $message->hasHeader('cache-control') ? 'cache-control' : 'pragma';
    $parsedComponents = createFieldValueComponentMap(parseFieldValueComponents($message, $header));

    if ($parsedComponents === null) {
        return ['no-store' => ''];
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

/**
 * @param CachedResponse $response
 *
 * @return int
 *
 * @see https://tools.ietf.org/html/rfc7234.html#section-4.2.1
 */
function calculateFreshnessLifetime(CachedResponse $response): int
{
    $cacheControl = parseCacheControlHeader($response);

    if (isset($cacheControl[ResponseCacheControlDirective::MAX_AGE])) {
        return $cacheControl[ResponseCacheControlDirective::MAX_AGE];
    }

    if ($response->hasHeader('expires')) {
        $headerCount = \count($response->getHeaderArray('expires'));
        if ($headerCount > 1) {
            return 0; // treat as expired
        }

        $expires = parseExpiresHeader($response->getHeader('expires'));
        $date = parseDateHeader($response->getHeader('date'));

        if ($date === null) {
            return 0; // treat as expired
        }

        return $expires->getTimestamp() - $date->getTimestamp();
    }

    return 0; // treat as expired, we don't implement heuristic freshness for now
}

/**
 * @param CachedResponse $response
 *
 * @return int
 *
 * @see https://tools.ietf.org/html/rfc7234.html#section-4.2.3
 */
function calculateAge(CachedResponse $response): int
{
    $date = parseDateHeader($response->getHeader('date'));
    if ($date === null) {
        /** @noinspection PhpUndefinedClassInspection */
        throw new \AssertionError('Got a cached response without date header, which should never happen. Please report this as a bug.');
    }

    $ageValue = (int) ($response->getHeader('age') ?? '0');

    $apparentAge = \max(0, $response->getResponseTime()->getTimestamp() - $date->getTimestamp());

    $responseDelay = $response->getResponseTime()->getTimestamp() - $response->getResponseTime()->getTimestamp();
    $correctedAgeValue = $ageValue + $responseDelay;

    $correctedInitialAge = \max($apparentAge, $correctedAgeValue);

    $residentTime = now()->getTimestamp() - $response->getResponseTime()->getTimestamp();

    return $correctedInitialAge + $residentTime;
}

function now(): \DateTimeImmutable
{
    /** @noinspection PhpUnhandledExceptionInspection */
    return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
}

/**
 * @param CachedResponse $response
 *
 * @return bool
 *
 * @see https://tools.ietf.org/html/rfc7234.html#section-5.3
 */
function isStale(CachedResponse $response): bool
{
    return !isFresh($response);
}

/**
 * @param CachedResponse $response
 *
 * @return bool
 *
 * @see https://tools.ietf.org/html/rfc7234.html#section-5.3
 */
function isFresh(CachedResponse $response)
{
    return calculateFreshnessLifetime($response) > calculateAge($response);
}

/**
 * @param Request        $request
 * @param CachedResponse ...$responses
 *
 * @return CachedResponse|null
 *
 * @see https://tools.ietf.org/html/rfc7234.html#section-4.1
 */
function selectStoredResponse(Request $request, CachedResponse ...$responses): ?CachedResponse
{
    $requestCacheControl = parseCacheControlHeader($request);

    $responses = sortMessagesByDateHeader($responses);

    foreach ($responses as $response) {
        $responseCacheControl = parseCacheControlHeader($response);

        $age = calculateAge($response);
        $lifetime = calculateFreshnessLifetime($response);

        if (isset($requestCacheControl['max-age']) && $age > $requestCacheControl['max-age']) {
            continue; // https://tools.ietf.org/html/rfc7234.html#section-5.2.1.1
        }

        if ($age >= $lifetime) { // stale
            if (isset($responseCacheControl['must-revalidate'])) {
                continue; // https://tools.ietf.org/html/rfc7234.html#section-5.2.2.1
            }

            $staleTime = $age - $lifetime;
            if (!isset($requestCacheControl['max-stale']) || $staleTime >= $requestCacheControl['max-stale']) {
                continue; // https://tools.ietf.org/html/rfc7234.html#section-5.2.1.2
            }
        }

        if (isset($requestCacheControl['min-fresh']) && $age + $requestCacheControl['min-fresh'] >= $lifetime) {
            continue; // https://tools.ietf.org/html/rfc7234.html#section-5.2.1.3
        }

        if (!$response->matches($request)) {
            continue;
        }

        return $response;
    }

    return null;
}

function sortMessagesByDateHeader(array $responses): array
{
    \usort($responses, static function (Message $a, Message $b): int {
        $dateA = parseDateHeader($a->getHeader('date'));
        $dateB = parseDateHeader($b->getHeader('date'));

        if ($dateA === null) {
            return 1;
        }

        if ($dateB === null) {
            return -1;
        }

        return $dateA <=> $dateB;
    });

    return $responses;
}
