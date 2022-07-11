<?php

namespace Amp\Http\Client\Cache;

use Amp\ByteStream;
use Amp\ByteStream\ReadableBuffer;
use Amp\ByteStream\ReadableIterableStream;
use Amp\ByteStream\ReadableStream;
use Amp\Cache\Cache;
use Amp\Cancellation;
use Amp\CancelledException;
use Amp\DeferredCancellation;
use Amp\DeferredFuture;
use Amp\Future;
use Amp\Http\Client\ApplicationInterceptor;
use Amp\Http\Client\Cache\Internal\CachedResponse;
use Amp\Http\Client\DelegateHttpClient;
use Amp\Http\Client\HttpException;
use Amp\Http\Client\Internal\ResponseBodyStream;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use Amp\Http\Message;
use Amp\Pipeline\Queue;
use Psr\Log\LoggerInterface as PsrLogger;
use Psr\Log\NullLogger;
use function Amp\async;
use function Amp\Http\formatDateHeader;

final class SingleUserCache implements ApplicationInterceptor
{
    private int $nextRequestId = 1;

    private int $requestCount = 0;

    private int $hitCount = 0;

    private int $networkCount = 0;

    /** @var array<string, Future<void>> */
    private array $pendingResponses = [];

    /** @var array<string, Future<void>> */
    private array $pushLocks = [];

    public function __construct(
        private readonly Cache $cache,
        private readonly PsrLogger $logger = new NullLogger(),
        private readonly int $responseSizeLimit = 1 << 20, // 1MB
        private readonly bool $storePushedResponses = true,
    ){
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

    public function request(
        Request $request,
        Cancellation $cancellation,
        DelegateHttpClient $httpClient
    ): Response {
        $this->requestCount++;

        if ($this->storePushedResponses) {
            $originalPushHandler = $request->getPushHandler();
            $request->setPushHandler(function (Request $request, Future $response) use ($originalPushHandler): void {
                $requestTime = now();
                $requestId = $this->nextRequestId++;

                $deferred = new DeferredFuture();
                $pushLockKey = $this->getPushLockKey($request);

                if (!isset($this->pushLocks[$pushLockKey])) {
                    $this->pushLocks[$pushLockKey] = $future = $deferred->getFuture();
                    $future->finally(function () use ($pushLockKey): void {
                        unset($this->pushLocks[$pushLockKey]);
                    });
                }

                try {
                    $this->logger->debug('Received pushed request for #{request_id}', [
                        'request_method' => $request->getMethod(),
                        'request_uri' => (string) $request->getUri()->withUserInfo(''),
                        'request_id' => $requestId,
                    ]);

                    $response = async(function () use (
                        $request,
                        $response,
                        $requestId,
                        $requestTime,
                        $deferred,
                        $originalPushHandler
                    ): Response {
                        $response = $response->await();

                        /** @var Response $response */
                        if ($originalPushHandler || $this->isCacheable($response)) {
                            return $this->storeResponse($request, $response, $requestId, $requestTime, $deferred);
                        }

                        throw new HttpException('Rejecting push, because it is not cacheable');
                    });

                    if ($originalPushHandler) {
                        $originalPushHandler($request, $response);
                    }
                } catch (\Throwable $e) {
                    $deferred->complete();

                    throw $e;
                }
            });

            $pushLockKey = $this->getPushLockKey($request);

            // Await pushed responses if they're already in-flight
            ($this->pushLocks[$pushLockKey] ?? null)?->await();
        }

        $originalRequest = clone $request;

        // Await pending response for the same resource
        ($this->pendingResponses[$this->getPrimaryCacheKey($originalRequest)] ?? null)?->await();

        $responses = $this->fetchStoredResponses($originalRequest);
        $cachedResponse = $this->selectStoredResponse($originalRequest, ...$responses);

        if ($cachedResponse === null) {
            return $this->fetchFreshResponse($httpClient, $request, $cancellation);
        }

        $cachedBody = $this->cache->get($this->getBodyCacheKey($cachedResponse->getBodyHash()));
        if ($cachedBody === null) {
            return $this->fetchFreshResponse($httpClient, $request, $cancellation);
        }

        $validBodyHash = \hash('sha512', $cachedBody) === $cachedResponse->getBodyHash();
        if (!$validBodyHash) {
            $this->logger->warning('Cache entry modification detected, please make sure several cache users don\'t interfere with each other by using a PrefixCache to give individual users their own cache key space.');
        }

        $requestHeader = parseCacheControlHeader($originalRequest);
        $responseHeader = parseCacheControlHeader($cachedResponse);

        if (!$validBodyHash || isset($requestHeader[RequestCacheControl::NO_CACHE]) || isset($responseHeader[ResponseCacheControl::NO_CACHE])) {
            return $this->fetchFreshResponse($httpClient, $request, $cancellation);
        }

        // TODO no-cache, requires validation

        $response = $this->createResponseFromCache(
            $cachedResponse,
            $originalRequest,
            new ReadableBuffer($cachedBody),
        );

        $response->setHeader('age', $cachedResponse->getAge());

        return $response;
    }

    private function fetchFreshResponse(
        DelegateHttpClient $httpClient,
        Request $request,
        Cancellation $cancellation
    ): Response {
        $requestCacheControl = parseCacheControlHeader($request);

        if (isset($requestCacheControl[RequestCacheControl::ONLY_IF_CACHED])) {
            return new Response($request->getProtocolVersions()[0], 504, 'No stored response available', [
                'date' => [formatDateHeader()],
            ], new ReadableBuffer(), $request);
        }

        $this->networkCount++;

        $requestTime = now();
        $requestId = $this->nextRequestId++;
        $originalRequest = clone $request;

        $this->logger->debug('Fetching fresh response for #{request_id}', [
            'request_method' => $originalRequest->getMethod(),
            'request_uri' => (string) $originalRequest->getUri()->withUserInfo(''),
            'request_id' => $requestId,
        ]);

        $response = $httpClient->request($request, $cancellation);

        return $this->storeResponse($originalRequest, $response, $requestId, $requestTime);
    }

    private function createResponseFromCache(
        CachedResponse $cachedResponse,
        Request $request,
        ReadableStream $bodyStream,
    ): Response {
        $this->hitCount++;

        $this->logger->debug('Serving response from cache', [
            'request_method' => $request->getMethod(),
            'request_uri' => (string) $request->getUri()->withUserInfo(''),
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

    private function fetchStoredResponses(Request $request): array
    {
        $rawCacheData = $this->cache->get($this->getPrimaryCacheKey($request));
        if ($rawCacheData === null) {
            return [];
        }

        $responses = [];
        $cacheData = \explode("\r\n", $rawCacheData);

        foreach ($cacheData as $responseData) {
            $responses[] = CachedResponse::fromCacheData(\base64_decode($responseData));
        }

        return $responses;
    }

    private function getPrimaryCacheKey(Request $request): string
    {
        return $request->getMethod() . ':' . $request->getUri();
    }

    private function storeResponses(string $cacheKey, array $responses): void
    {
        $encodedDataSet = [];
        foreach ($responses as $response) {
            \assert($response instanceof CachedResponse);

            $encodedDataSet[] = \base64_encode($response->toCacheData());
        }

        $rawCacheData = \implode("\r\n", $encodedDataSet);

        $this->cache->set($cacheKey, $rawCacheData, $this->calculateTtl($responses));
    }

    private function calculateTtl(array $responses): int
    {
        $ttl = 0;

        foreach ($responses as $response) {
            \assert($response instanceof CachedResponse);

            $ttl = \max($ttl, $response->getFreshnessLifetime() - $response->getAge());
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
        if (isset($requestHeader[RequestCacheControl::NO_STORE])) {
            return false;
        }

        $responseHeader = parseCacheControlHeader($response);
        if (isset($responseHeader[ResponseCacheControl::NO_STORE])) {
            return false;
        }

        if (!isset($responseHeader[ResponseCacheControl::MAX_AGE]) && !$response->hasHeader('expires')) {
            return false;
        }

        return true;
    }

    private function createTeeStream(ReadableStream $stream, int $count): array
    {
        /** @var Queue[] $queues */
        $queues = [];
        /** @var ReadableStream[] $streams */
        $streams = [];
        /** @var Cancellation[] $cancellations */
        $cancellations = [];

        for ($i = 0; $i < $count; $i++) {
            $queue = new Queue();
            $queues[] = $queue;

            $deferredCancellation = new DeferredCancellation();
            $streams[] = new ResponseBodyStream(new ReadableIterableStream($queue->iterate()), $deferredCancellation);

            $cancellations[] = $deferredCancellation->getCancellation();
        }

        async(static function () use ($stream, $queues, $cancellations, $count): void {
            try {
                while (null !== $chunk = $stream->read()) {
                    $cancelled = 0;

                    foreach ($cancellations as $index => $cancellationToken) {
                        if ($cancellationToken->isRequested()) {
                            if (isset($queues[$index])) {
                                $queues[$index]->error(new CancelledException);
                                unset($queues[$index]);
                            }

                            $cancelled++;
                        }
                    }

                    if ($cancelled === $count) {
                        unset($stream);
                        return;
                    }

                    $futures = [];
                    foreach ($queues as $queue) {
                        $futures[] = $queue->pushAsync($chunk);
                    }

                    Future\awaitAll($futures);
                }

                foreach ($queues as $queue) {
                    $queue->complete();
                }
            } catch (\Throwable $e) {
                foreach ($queues as $queue) {
                    $queue->error($e);
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
        \DateTimeImmutable $requestTime,
        ?DeferredFuture $pushDeferred = null,
    ): Response {
        try {
            if (!$response->hasHeader('date')) {
                $response->setHeader('date', formatDateHeader());
            }

            $responseTime = now();

            $message = 'Received ' . ($pushDeferred ? 'pushed ' : '') . 'response in {response_duration_formatted} for #{request_id}';
            $this->logger->debug($message, [
                'response_duration_formatted' => $this->formatDuration($responseTime, $requestTime),
                'request_method' => $originalRequest->getMethod(),
                'request_uri' => (string) $originalRequest->getUri()->withUserInfo(''),
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
                [$streamA, $streamB] = $this->createTeeStream($response->getBody(), 2);

                $response->setBody($streamA);

                $primaryCacheKey = $this->getPrimaryCacheKey($response->getRequest());
                $this->pendingResponses[$primaryCacheKey] = async(function () use (
                    $primaryCacheKey,
                    $response,
                    $streamB,
                    $requestTime,
                    $responseTime,
                    $pushDeferred,
                ): void {
                    try {
                        $bufferedBody = ByteStream\buffer($streamB, limit: $this->responseSizeLimit);

                        $bodyHash = \hash('sha512', $bufferedBody);

                        $responseToStore = CachedResponse::fromResponse(
                            $response->getRequest(),
                            $response,
                            $requestTime,
                            $responseTime,
                            $bodyHash
                        );

                        $ttl = $this->calculateTtl([$responseToStore]);

                        $this->cache->set($this->getBodyCacheKey($bodyHash), $bufferedBody, $ttl);

                        $storedResponses = $this->fetchStoredResponses($response->getRequest());
                        $storedResponses[] = $responseToStore;

                        $this->storeResponses(
                            $this->getPrimaryCacheKey($response->getRequest()),
                            $storedResponses
                        );
                    } catch (ByteStream\BufferException) {
                        return;
                    } catch (\Throwable $e) {
                        $this->logger->warning('Failed to store response for {request_uri} in cache due to an exception', [
                            'request_uri' => $response->getRequest()->getUri()->withUserInfo(''),
                            'exception' => $e,
                        ]);
                    } finally {
                        $pushDeferred?->complete();
                        unset($this->pendingResponses[$primaryCacheKey]);
                    }
                });
            } elseif ($pushDeferred) {
                $pushDeferred->complete();
            }

            return $response;
        } catch (\Throwable $e) {
            $pushDeferred?->complete();

            throw $e;
        }
    }

    private function getPushLockKey(Request $request): string
    {
        return $request->getMethod() . ' ' . $request->getUri()->withUserInfo('');
    }

    /**
     * @param Request        $request
     * @param CachedResponse ...$responses
     *
     * @return CachedResponse|null
     *
     * @see https://tools.ietf.org/html/rfc7234.html#section-4.1
     */
    private function selectStoredResponse(Request $request, CachedResponse ...$responses): ?CachedResponse
    {
        $requestCacheControl = parseCacheControlHeader($request);

        $responses = $this->sortMessagesByDateHeader($responses);

        foreach ($responses as $response) {
            $responseCacheControl = parseCacheControlHeader($response);

            $age = $response->getAge();
            $lifetime = $response->getFreshnessLifetime();

            if (isset($requestCacheControl[ResponseCacheControl::MAX_AGE]) && $age > $requestCacheControl[RequestCacheControl::MAX_AGE]) {
                continue; // https://tools.ietf.org/html/rfc7234.html#section-5.2.1.1
            }

            if ($age >= $lifetime) { // stale
                if (isset($responseCacheControl[ResponseCacheControl::MUST_REVALIDATE])) {
                    continue; // https://tools.ietf.org/html/rfc7234.html#section-5.2.2.1
                }

                $staleTime = $age - $lifetime;
                if (!isset($requestCacheControl[RequestCacheControl::MAX_STALE]) || $staleTime >= $requestCacheControl[RequestCacheControl::MAX_STALE]) {
                    continue; // https://tools.ietf.org/html/rfc7234.html#section-5.2.1.2
                }
            }

            if (isset($requestCacheControl[RequestCacheControl::MIN_FRESH]) && $age + $requestCacheControl[RequestCacheControl::MIN_FRESH] >= $lifetime) {
                continue; // https://tools.ietf.org/html/rfc7234.html#section-5.2.1.3
            }

            if (!$response->matches($request)) {
                continue;
            }

            return $response;
        }

        return null;
    }

    private function sortMessagesByDateHeader(array $responses): array
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
}
