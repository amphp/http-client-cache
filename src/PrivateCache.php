<?php

namespace Amp\Http\Client\Cache;

use Amp\ByteStream\InMemoryStream;
use Amp\ByteStream\InputStream;
use Amp\ByteStream\IteratorStream;
use Amp\Cache\Cache as StringCache;
use Amp\CancellationToken;
use Amp\Emitter;
use Amp\Http\Client\ApplicationInterceptor;
use Amp\Http\Client\Client;
use Amp\Http\Client\ConnectionInfo;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use Amp\Promise;
use Amp\Socket\SocketAddress;
use Psr\Log\LoggerInterface as PsrLogger;
use Psr\Log\NullLogger;
use function Amp\asyncCall;
use function Amp\ByteStream\buffer;
use function Amp\call;

final class PrivateCache implements ApplicationInterceptor
{
    /** @var StringCache */
    private $cache;

    /** @var PsrLogger */
    private $logger;

    public function __construct(StringCache $cache, ?PsrLogger $logger = null)
    {
        $this->cache = $cache;
        $this->logger = $logger ?? new NullLogger;
    }

    public function interceptApplicationRequest(
        Request $request,
        CancellationToken $cancellationToken,
        Client $next
    ): Promise {
        return call(function () use ($request, $cancellationToken, $next) {
            $responses = yield $this->fetchStoredResponses($request);
            $cachedResponse = selectStoredResponse($request, ...$responses);

            if ($cachedResponse === null) {
                return $this->fetchFreshResponse($next, $request, $cancellationToken);
            }

            $cachedBody = yield $this->cache->get($this->getBodyCacheKey($cachedResponse->getBodyHash()));
            $validBodyHash = \hash('sha512', $cachedBody) !== $cachedResponse->getBodyHash();

            $requestHeader = parseCacheControlHeader($request);
            $responseHeader = parseCacheControlHeader($cachedResponse);

            if ($cachedBody === null || $validBodyHash || isset($requestHeader['no-cache']) || isset($responseHeader['no-cache'])) {
                return $this->fetchFreshResponse($next, $request, $cancellationToken);
            }

            // TODO no-cache, requires validation

            $response = $this->createResponseFromCache($cachedResponse, $request, new InMemoryStream($cachedBody));
            $response = $response->withHeader('age', calculateAge($cachedResponse));

            return $response;
        });
    }

    private function fetchFreshResponse(Client $client, Request $request, CancellationToken $cancellationToken): Promise
    {
        return call(function () use ($client, $request, $cancellationToken) {
            $requestCacheControl = parseCacheControlHeader($request);

            if (isset($requestCacheControl['only-if-cached'])) {
                return new Response($request->getProtocolVersions()[0], 504, 'No stored response available', [
                    'date' => [\gmdate('D, d M Y H:i:s') . ' GMT'],
                ], new InMemoryStream, $request, new ConnectionInfo(new SocketAddress(''), new SocketAddress('')));
            }

            $requestTime = now();

            $this->logger->debug('Fetching fresh response', [
                'request_method' => $request->getMethod(),
                'request_host' => (string) $request->getUri()->getHost(),
            ]);

            $response = yield $client->request($request, $cancellationToken);
            \assert($response instanceof Response);

            $responseTime = now();

            $this->logger->debug('Received response in {response_duration_formatted}', [
                'response_duration_formatted' => $this->formatDuration($responseTime, $requestTime),
                'request_method' => $request->getMethod(),
                'request_host' => (string) $request->getUri()->getHost(),
            ]);

            // Another interceptor might have modified the URI or method, don't cache then
            $sameMethod = $response->getRequest()->getMethod() === $request->getMethod();
            $sameUri = (string) $response->getRequest()->getUri() === (string) $request->getUri();

            if (!$sameMethod || !$sameUri) {
                $this->logger->warning('Won\'t store response in cache, because request method or request URI have been modified by an interceptor. '
                    . 'Please check whether you can move such an interceptor up in the chain, so the method and URI are modified before the cache is invoked.');

                return $response;
            }

            if ($this->isCacheable($response)) {
                [$streamA, $streamB] = $this->createTeeStream($response->getBody());

                $response = $response->withBody($streamA);

                asyncCall(function () use ($request, $response, $streamB, $requestTime, $responseTime) {
                    try {
                        $bufferedBody = yield buffer($streamB);
                        $bodyHash = \hash('sha512', $bufferedBody);

                        $responseToStore = CachedResponse::fromResponse(
                            $response->withRequest($request),
                            $requestTime,
                            $responseTime,
                            $bodyHash
                        );

                        $ttl = $this->calculateTtl([$responseToStore]);

                        yield $this->cache->set($this->getBodyCacheKey($bodyHash), $bufferedBody, $ttl);

                        $storedResponses = (yield $this->fetchStoredResponses($request)) ?? [];
                        $storedResponses[] = $responseToStore;

                        yield $this->storeResponses($this->getPrimaryCacheKey($request), $storedResponses);
                    } catch (\Throwable $e) {
                        $this->logger->warning('Failed to store response in cache due to an exception', [
                            'exception' => $e,
                        ]);
                    }
                });
            }

            return $response;
        });
    }

    private function createResponseFromCache(
        CachedResponse $cachedResponse,
        Request $request,
        InputStream $bodyStream
    ): Response {
        $this->logger->debug('Serving response from cache', [
            'request_method' => $request->getMethod(),
            'request_host' => (string) $request->getUri()->getHost(),
        ]);

        return new Response(
            $cachedResponse->getProtocolVersion(),
            $cachedResponse->getStatus(),
            $cachedResponse->getReason(),
            $cachedResponse->getHeaders(),
            $bodyStream,
            $request,
            new ConnectionInfo(new SocketAddress(''), new SocketAddress(''))
        );
    }

    private function fetchStoredResponses(Request $request): Promise
    {
        return call(function () use ($request) {
            $rawCacheData = yield $this->cache->get($this->getPrimaryCacheKey($request));
            if ($rawCacheData === null) {
                return [];
            }

            $responses = [];
            $cacheData = \explode("\r\n", $rawCacheData);

            foreach ($cacheData as $responseData) {
                $responses[] = CachedResponse::fromCacheData(\base64_decode($responseData));
            }

            return $responses;
        });
    }

    private function getPrimaryCacheKey(Request $request): string
    {
        return $request->getMethod() . ' ' . $request->getUri();
    }

    private function storeResponses(string $cacheKey, array $responses): Promise
    {
        return call(function () use ($cacheKey, $responses) {
            $encodedDataSet = [];
            foreach ($responses as $response) {
                \assert($response instanceof CachedResponse);

                $encodedDataSet[] = \base64_encode($response->toCacheData());
            }

            $rawCacheData = \implode("\r\n", $encodedDataSet);

            return $this->cache->set($cacheKey, $rawCacheData, $this->calculateTtl($responses));
        });
    }

    private function calculateTtl(array $responses): int
    {
        $ttl = 0;

        foreach ($responses as $response) {
            \assert($response instanceof CachedResponse);

            $ttl = \max($ttl, calculateFreshnessLifetime($response) - calculateAge($response));
        }

        return $ttl;
    }

    private function getBodyCacheKey(string $bodyHash): string
    {
        return 'body:' . $bodyHash;
    }

    /**
     * @param string $method
     *
     * @return bool
     *
     * @see https://tools.ietf.org/html/rfc7231#section-4.2.3
     */
    private function isCacheableRequestMethod(string $method): bool
    {
        return \in_array($method, ['GET', 'HEAD'], true);
    }

    /**
     * @param int $status
     *
     * @return bool
     *
     * @see https://tools.ietf.org/html/rfc7231.html#section-6.1
     */
    private function isCacheableResponseCode(int $status): bool
    {
        // exclude 206, because we don't support partial responses
        return \in_array($status, [200, 203, 204, 300, 301, 404, 405, 410, 414, 501], true);
    }

    /**
     * @param Response $response
     *
     * @return bool
     *
     * @see https://tools.ietf.org/html/rfc7234.html#section-3
     */
    private function isCacheable(Response $response): bool
    {
        $request = $response->getRequest();

        if (!$this->isCacheableRequestMethod($request->getMethod())) {
            return false;
        }

        if (!$this->isCacheableResponseCode($response->getStatus())) {
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

    private function createTeeStream(InputStream $inputStream, int $count = 2): array
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
                while (null !== $chunk = yield $inputStream->read()) {
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

    private function formatDuration(\DateTimeInterface $a, \DateTimeInterface $b): string
    {
        $diff = $a->diff($b, true);

        $result = '';

        if ($diff->y) {
            $plural = $diff->y === 1 ? '' : 's';
            $result .= $diff->format("%y year{$plural} ");
        }

        if ($diff->m) {
            $plural = $diff->m === 1 ? '' : 's';
            $result .= $diff->format("%m month{$plural} ");
        }

        if ($diff->d) {
            $plural = $diff->d === 1 ? '' : 's';
            $result .= $diff->format("%d day{$plural} ");
        }

        if ($diff->h) {
            $plural = $diff->h === 1 ? '' : 's';
            $result .= $diff->format("%h hour{$plural} ");
        }

        if ($diff->i) {
            $plural = $diff->i === 1 ? '' : 's';
            $result .= $diff->format("%i minute{$plural} ");
        }

        if ($diff->s) {
            $plural = $diff->s === 1 ? '' : 's';
            $result .= $diff->format("%s second{$plural} ");
        }

        $plural = $diff->f === 1 ? '' : 's';
        $result .= \round($diff->f * 1000) . " millisecond{$plural}.";

        return $result;
    }
}
