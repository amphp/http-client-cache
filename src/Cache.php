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

final class Cache implements ApplicationInterceptor
{

    // TODO a cache generated response must have an 'Age' header
    // see https://tools.ietf.org/html/rfc7234.html#section-5.1

    // TODO Add versioning to cache to detect overrides and retry

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

            $cachedBody = yield $this->cache->get($this->getVaryCacheKey($request, $cachedResponse->getVaryHash()));

            if ($cachedBody === null) {
                return $this->fetchFreshResponse($next, $request, $cancellationToken);
            }

            return $this->createResponseFromCache($cachedResponse, new InMemoryStream($cachedBody));
        });
    }

    private function getVaryCacheKey(Request $request, string $varyHash): string
    {
        return getPrimaryCacheKey($request) . ' ' . $varyHash;
    }

    private function calculateVaryHash(Response $response): string
    {
        $headers = [];

        $varyHeaderValues = $response->getHeaderArray('vary');
        $varyHeaders = \array_map('trim', \explode(',', \implode(',', $varyHeaderValues)));

        $request = $response->getRequest();

        foreach ($varyHeaders as $varyHeader) {
            $headers[\strtolower($varyHeader)] = $request->getHeaderArray($varyHeader);
        }

        return \base64_encode(\hash('sha256', \json_encode($headers, \JSON_THROW_ON_ERROR), true));
    }

    private function fetchFreshResponse(Client $client, Request $request, CancellationToken $cancellationToken): Promise
    {
        return call(function () use ($client, $request, $cancellationToken) {
            $requestTime = now();

            $response = yield $client->request($request, $cancellationToken);

            $responseTime = now();

            \assert($response instanceof Response);

            if (isCacheable($response)) {
                [$streamA, $streamB] = createTeeStream($response->getBody());

                $response = $response->withBody($streamA);

                asyncCall(function () use ($request, $response, $streamB, $requestTime, $responseTime) {
                    $bufferedBody = yield buffer($streamB);
                    $varyHash = $this->calculateVaryHash($response);

                    $responseToStore = CachedResponse::fromResponse(
                        $response->withRequest($request),
                        $requestTime,
                        $responseTime,
                        $varyHash
                    );

                    $ttl = $this->calculateTtl([$responseToStore]);

                    yield $this->cache->set($this->getVaryCacheKey($request, $varyHash), $bufferedBody, $ttl);

                    $storedResponses = (yield $this->fetchStoredResponses($request)) ?? [];
                    $storedResponses[] = $responseToStore;

                    yield $this->storeResponses(getPrimaryCacheKey($request), $storedResponses);
                });
            }

            return $response;
        });
    }

    private function createResponseFromCache(CachedResponse $cachedResponse, InputStream $bodyStream): Response
    {
        return new Response(
            $cachedResponse->getProtocolVersion(),
            $cachedResponse->getStatus(),
            $cachedResponse->getReason(),
            $cachedResponse->getHeaders(),
            $bodyStream,
            $cachedResponse->getRequest(),
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
}
