<?php

namespace Amp\Http\Client\Cache;

use Amp\ByteStream\InMemoryStream;
use Amp\ByteStream\InputStream;
use Amp\Cache\Cache as StringCache;
use Amp\CancellationToken;
use Amp\Http\Client\ApplicationInterceptor;
use Amp\Http\Client\Client;
use Amp\Http\Client\ConnectionInfo;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use Amp\Promise;
use Amp\Socket\SocketAddress;
use function Amp\asyncCall;
use function Amp\ByteStream\buffer;
use function Amp\call;

final class PrivateCache implements ApplicationInterceptor
{
    /** @var StringCache */
    private $cache;

    public function __construct(StringCache $cache)
    {
        $this->cache = $cache;
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

            if ($cachedBody === null || \hash('sha512', $cachedBody) !== $cachedResponse->getBodyHash()) {
                return $this->fetchFreshResponse($next, $request, $cancellationToken);
            }

            $response = $this->createResponseFromCache($cachedResponse, $request, new InMemoryStream($cachedBody));
            $response = $response->withHeader('age', calculateAge($cachedResponse));

            return $response;
        });
    }

    private function fetchFreshResponse(Client $client, Request $request, CancellationToken $cancellationToken): Promise
    {
        return call(function () use ($client, $request, $cancellationToken) {
            $requestTime = now();

            $response = yield $client->request($request, $cancellationToken);
            \assert($response instanceof Response);

            // Another interceptor might have modified the URI or method, don't cache then
            $sameMethod = $response->getRequest()->getMethod() === $request->getMethod();
            $sameUri = (string) $response->getRequest()->getUri() === (string) $request->getUri();

            if (!$sameMethod || !$sameUri) {
                // TODO: Log a warning that the original response has been modified and thus we can't cache
                return $response;
            }

            $responseTime = now();

            if (isCacheable($response)) {
                [$streamA, $streamB] = createTeeStream($response->getBody());

                $response = $response->withBody($streamA);

                asyncCall(function () use ($request, $response, $streamB, $requestTime, $responseTime) {
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

                    yield $this->storeResponses(getPrimaryCacheKey($request), $storedResponses);
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
            $rawCacheData = yield $this->cache->get(getPrimaryCacheKey($request));
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
}
