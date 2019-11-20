<?php

namespace Amp\Http\Client\Cache;

use Amp\ByteStream\InMemoryStream;
use Amp\ByteStream\InputStream;
use Amp\ByteStream\IteratorStream;
use Amp\Cache\Cache;
use Amp\CancellationToken;
use Amp\CancellationTokenSource;
use Amp\CancelledException;
use Amp\Emitter;
use Amp\Http\Client\ApplicationInterceptor;
use Amp\Http\Client\DelegateHttpClient;
use Amp\Http\Client\Internal\ResponseBodyStream;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use Amp\Promise;
use Psr\Log\LoggerInterface as PsrLogger;
use Psr\Log\NullLogger;
use function Amp\asyncCall;
use function Amp\call;
use function Amp\Http\formatDateHeader;

final class SingleUserCache implements ApplicationInterceptor
{
    /** @var int */
    private $nextRequestId = 1;

    /** @var Cache */
    private $cache;

    /** @var PsrLogger */
    private $logger;

    /** @var int */
    private $responseSizeLimit;

    /** @var int */
    private $requestCount = 0;

    /** @var int */
    private $hitCount = 0;

    /** @var int */
    private $networkCount = 0;

    public function __construct(Cache $cache, ?PsrLogger $logger = null)
    {
        $this->cache = $cache;
        $this->logger = $logger ?? new NullLogger;
        $this->responseSizeLimit = 1 * 1024 * 1024; // 1MB
    }

    public function getRequestCount(): int
    {
        return $this->requestCount;
    }

    public function getHitCount(): int
    {
        return $this->hitCount;
    }

    public function getNetworkCount(): int
    {
        return $this->networkCount;
    }

    public function setResponseSizeLimit(int $limit): void
    {
        $this->responseSizeLimit = $limit;
    }

    public function getResponseSizeLimit(): int
    {
        return $this->responseSizeLimit;
    }

    public function request(
        Request $request,
        CancellationToken $cancellation,
        DelegateHttpClient $client
    ): Promise {
        return call(function () use ($request, $cancellation, $client) {
            $this->requestCount++;

            $originalRequest = clone $request;

            $responses = yield $this->fetchStoredResponses($originalRequest);
            $cachedResponse = selectStoredResponse($originalRequest, ...$responses);

            if ($cachedResponse === null) {
                return $this->fetchFreshResponse($client, $request, $cancellation);
            }

            $cachedBody = yield $this->cache->get($this->getBodyCacheKey($cachedResponse->getBodyHash()));
            if ($cachedBody === null) {
                return $this->fetchFreshResponse($client, $request, $cancellation);
            }

            $validBodyHash = \hash('sha512', $cachedBody) === $cachedResponse->getBodyHash();
            if (!$validBodyHash) {
                $this->logger->warning('Cache entry modification detected, please make sure several cache users don\'t interfere with each other by using a PrefixCache to give individual users their own cache key space.');
            }

            $requestHeader = parseCacheControlHeader($originalRequest);
            $responseHeader = parseCacheControlHeader($cachedResponse);

            if (!$validBodyHash || isset($requestHeader['no-cache']) || isset($responseHeader['no-cache'])) {
                return $this->fetchFreshResponse($client, $request, $cancellation);
            }

            // TODO no-cache, requires validation

            $response = $this->createResponseFromCache(
                $cachedResponse,
                $originalRequest,
                new InMemoryStream($cachedBody)
            );

            $response->setHeader('age', calculateAge($cachedResponse));

            return $response;
        });
    }

    private function fetchFreshResponse(
        DelegateHttpClient $client,
        Request $request,
        CancellationToken $cancellation
    ): Promise {
        return call(function () use ($client, $request, $cancellation) {
            $requestCacheControl = parseCacheControlHeader($request);

            if (isset($requestCacheControl['only-if-cached'])) {
                return new Response($request->getProtocolVersions()[0], 504, 'No stored response available', [
                    'date' => [formatDateHeader()],
                ], new InMemoryStream, $request);
            }

            $this->networkCount++;

            $requestTime = now();
            $requestId = $this->nextRequestId++;
            $originalRequest = clone $request;

            $this->logger->debug('Fetching fresh response for #{request_id}', [
                'request_method' => $originalRequest->getMethod(),
                'request_host' => (string) $originalRequest->getUri()->getHost(),
                'request_id' => $requestId,
            ]);

            $response = yield $client->request($request, $cancellation);

            \assert($response instanceof Response);

            return $this->storeResponse($originalRequest, $response, $requestId, $requestTime);
        });
    }

    private function createResponseFromCache(
        CachedResponse $cachedResponse,
        Request $request,
        InputStream $bodyStream
    ): Response {
        $this->hitCount++;

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
            $request
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
        /** @var InputStream[] $streams */
        $streams = [];
        /** @var CancellationToken[] $cancellationTokens */
        $cancellationTokens = [];

        for ($i = 0; $i < $count; $i++) {
            $emitter = new Emitter;
            $emitters[] = $emitter;

            $cancellationTokenSource = new CancellationTokenSource;
            $streams[] = new ResponseBodyStream(new IteratorStream($emitter->iterate()), $cancellationTokenSource);

            $cancellationTokens[] = $cancellationTokenSource->getToken();
        }

        asyncCall(static function () use ($inputStream, $emitters, $cancellationTokens, $count) {
            try {
                while (null !== $chunk = yield $inputStream->read()) {
                    $cancelled = 0;

                    foreach ($cancellationTokens as $index => $cancellationToken) {
                        if ($cancellationToken->isRequested()) {
                            if (isset($emitters[$index])) {
                                $emitters[$index]->fail(new CancelledException);
                                $emitters[$index] = null;
                            }

                            $cancelled++;
                        }
                    }

                    if ($cancelled === $count) {
                        unset($inputStream);

                        return;
                    }

                    $promises = [];

                    foreach ($emitters as $emitter) {
                        if ($emitter !== null) {
                            $promises[] = $emitter->emit($chunk);
                        }
                    }

                    yield Promise\first($promises);
                }

                foreach ($emitters as $emitter) {
                    if ($emitter !== null) {
                        $emitter->complete();
                    }
                }
            } catch (\Throwable $e) {
                foreach ($emitters as $emitter) {
                    if ($emitter !== null) {
                        $emitter->fail($e);
                    }
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
        $result .= \round($diff->f * 1000) . " millisecond{$plural}";

        return $result;
    }

    private function storeResponse(
        Request $originalRequest,
        Response $response,
        int $requestId,
        \DateTimeImmutable $requestTime
    ): Promise {
        return call(function () use ($originalRequest, $response, $requestId, $requestTime) {
            if (!$response->hasHeader('date')) {
                $response->setHeader('date', formatDateHeader());
            }

            $responseTime = now();

            $this->logger->debug('Received response in {response_duration_formatted} for #{request_id}', [
                'response_duration_formatted' => $this->formatDuration($responseTime, $requestTime),
                'request_method' => $originalRequest->getMethod(),
                'request_host' => (string) $originalRequest->getUri()->getHost(),
                'request_id' => $requestId,
            ]);

            // Another interceptor might have modified the URI or method, don't cache then
            $sameMethod = $response->getRequest()->getMethod() === $originalRequest->getMethod();
            $sameUri = (string) $response->getRequest()->getUri() === (string) $originalRequest->getUri();

            if (!$sameMethod || !$sameUri) {
                $this->logger->warning('Won\'t store response in cache, because request method or request URI have been modified by an interceptor. '
                    . 'Please check whether you can move such an interceptor up in the chain, so the method and URI are modified before the cache is invoked.');

                return $response;
            }

            if ($this->isCacheable($response)) {
                [$streamA, $streamB] = $this->createTeeStream($response->getBody());

                $response->setBody($streamA);

                asyncCall(function () use ($response, $streamB, $requestTime, $responseTime) {
                    try {
                        $bufferedBody = '';

                        while (null !== $chunk = yield $streamB->read()) {
                            $bufferedBody .= $chunk;

                            if (\strlen($bufferedBody) > $this->responseSizeLimit) {
                                return;
                            }
                        }

                        $bodyHash = \hash('sha512', $bufferedBody);

                        $responseToStore = CachedResponse::fromResponse(
                            $response->getRequest(),
                            $response,
                            $requestTime,
                            $responseTime,
                            $bodyHash
                        );

                        $ttl = $this->calculateTtl([$responseToStore]);

                        yield $this->cache->set($this->getBodyCacheKey($bodyHash), $bufferedBody, $ttl);

                        $storedResponses = yield $this->fetchStoredResponses($response->getRequest());
                        $storedResponses[] = $responseToStore;

                        yield $this->storeResponses(
                            $this->getPrimaryCacheKey($response->getRequest()),
                            $storedResponses
                        );
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
}
