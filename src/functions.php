<?php

namespace Amp\Http\Client\Cache;

use Amp\ByteStream\InputStream;
use Amp\ByteStream\IteratorStream;
use Amp\Emitter;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use Amp\Http\Message;
use Amp\Promise;
use function Amp\asyncCall;
use function Amp\Http\createFieldValueComponentMap;
use function Amp\Http\parseFieldValueComponents;

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

    if (\filter_var($value, \FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 0, 'max_range' => \PHP_INT_MAX]]) !== false) {
        return (int) $value;
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
            $value = parseDeltaSeconds($value) ?? 0;
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

function getPrimaryCacheKey(Request $request): string
{
    return $request->getMethod() . ' ' . $request->getUri();
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

function isCacheableRequestMethod(string $method): bool
{
    return \in_array($method, ['GET', 'HEAD'], true);
}

function isCacheableResponseCode(int $status): bool
{
    return \in_array($status, [200, 404, 410], true); // TODO Implement
}

/**
 * @param Response $response
 *
 * @return bool
 *
 * @see https://tools.ietf.org/html/rfc7234.html#section-3
 */
function isCacheable(Response $response): bool
{
    $request = $response->getRequest();

    if (!isCacheableRequestMethod($request->getMethod())) {
        return false;
    }

    if (!isCacheableResponseCode($response->getStatus())) {
        return false;
    }

    $requestHeader = parseCacheControlHeader($request);
    if (isset($requestHeader['no-store'])) {
        return false;
    }

    $responseHeader = parseCacheControlHeader($response);
    if (isset($responseHeader['no-store'])) {
        return false;
    }

    if (!isset($responseHeader['max-age']) && !$response->hasHeader('expires')) {
        return false;
    }

    return true;
}

/**
 * @param Request        $request
 * @param CachedResponse ...$responses
 *
 * @return Response|null
 *
 * @see https://tools.ietf.org/html/rfc7234.html#section-4.1
 */
function selectStoredResponse(Request $request, CachedResponse ...$responses): ?CachedResponse
{
    $responses = sortMessagesByDateHeader($responses);

    foreach ($responses as $response) {
        // TODO Implement section 4.2.4 and 4.3 (serving stale responses and validation)
        if (isStale($response)) {
            continue;
        }

        if (!$response->hasHeader('vary')) {
            return $response;
        }

        $varyHeaderValues = $response->getHeaderArray('vary');
        foreach ($varyHeaderValues as $varyHeaderValue) {
            if ($varyHeaderValue === '*') {
                continue 2; // 'A Vary header field-value of "*" always fails to match.'
            }
        }

        $varyHeaders = \array_map('trim', \explode(',', \implode(',', $varyHeaderValues)));

        $originalRequest = $response->getRequest();

        foreach ($varyHeaders as $varyHeader) {
            if ($request->getHeaderArray($varyHeader) !== $originalRequest->getHeaderArray($varyHeader)) {
                continue 2;
            }
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

function createTeeStream(InputStream $inputStream, int $count = 2): array
{
    /** @var Emitter[] $emitters */
    $emitters = [];
    $streams = [];

    for ($i = 0; $i < $count; $i++) {
        $emitter = new Emitter;

        $emitters[] = $emitter;
        $streams[] = new IteratorStream($emitter->iterate());
    }

    asyncCall(static function () use ($inputStream, $emitters) {
        try {
            while (null !== $chunk = $inputStream->read()) {
                $promises = [];

                foreach ($emitters as $emitter) {
                    $promises[] = $emitter->emit($chunk);
                }

                yield Promise\any($promises);
            }

            foreach ($emitters as $emitter) {
                $emitter->complete();
            }
        } catch (\Throwable $e) {
            foreach ($emitters as $emitter) {
                $emitter->fail($e);
            }
        }
    });

    return $streams;
}