<?php

namespace Amp\Http\Client\Cache\Internal;

use Amp\Http\Client\Cache\ResponseCacheControl;
use Amp\Http\Client\HttpException;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use Amp\Http\Message;
use function Amp\Http\Client\Cache\isFresh;
use function Amp\Http\Client\Cache\now;
use function Amp\Http\Client\Cache\parseCacheControlHeader;
use function Amp\Http\Client\Cache\parseDateHeader;
use function Amp\Http\Client\Cache\parseExpiresHeader;
use function Amp\Http\createFieldValueComponentMap;
use function Amp\Http\parseFieldValueComponents;

/** @internal */
final class CachedResponse extends Message
{
    public static function fromResponse(
        Request $request,
        Response $response,
        \DateTimeImmutable $requestTime,
        \DateTimeImmutable $responseTime,
        string $bodyHash
    ): self {
        return new self(
            $response->getProtocolVersion(),
            $response->getStatus(),
            $response->getReason(),
            $response->getHeaders(),
            $request,
            $requestTime,
            $responseTime,
            $bodyHash
        );
    }

    /**
     * @param string $data
     *
     * @return CachedResponse
     * @throws HttpException
     */
    public static function fromCacheData(string $data): self
    {
        try {
            $data = \json_decode($data, true, 4);
            if ($data === null) {
                throw new HttpException('Failed to decode cached data, JSON syntax error');
            }

            if (!isset(
                $data['protocol_version'],
                $data['status'],
                $data['reason'],
                $data['headers'],
                $data['request_method'],
                $data['request_target'],
                $data['request_vary'],
                $data['request_time'],
                $data['response_time'],
                $data['body_hash']
            )) {
                throw new HttpException('Failed to decode cached data, expected key not present');
            }

            $request = new Request($data['request_target'], $data['request_method']);
            $request->setHeaders($data['request_vary']);

            return new self(
                $data['protocol_version'],
                $data['status'],
                $data['reason'],
                $data['headers'],
                $request,
                new \DateTimeImmutable('@' . $data['request_time'], new \DateTimeZone('UTC')),
                new \DateTimeImmutable('@' . $data['response_time'], new \DateTimeZone('UTC')),
                $data['body_hash']
            );
        } catch (\Exception $e) {
            if ($e instanceof HttpException) {
                throw $e;
            }

            throw new HttpException('Failed to decode cached data');
        }
    }

    /** @var \DateTimeImmutable */
    private $requestTime;
    /** @var \DateTimeImmutable */
    private $responseTime;

    /** @var string */
    private $protocolVersion;
    /** @var int */
    private $status;
    /** @var string */
    private $reason;

    /** @var string */
    private $requestMethod;
    /** @var string */
    private $requestTarget;
    /** @var string[][] */
    private $requestHeaders;

    /** @var string */
    private $bodyHash;

    private function __construct(
        string $protocolVersion,
        int $status,
        string $reason,
        array $headers,
        Request $request,
        \DateTimeImmutable $requestTime,
        \DateTimeImmutable $responseTime,
        string $bodyHash
    ) {
        $this->protocolVersion = $protocolVersion;
        $this->status = $status;
        $this->reason = $reason;

        /** @noinspection PhpUnhandledExceptionInspection */
        $this->setHeaders($headers);

        $this->requestMethod = $request->getMethod();
        $this->requestTarget = (string) $request->getUri();
        $this->requestHeaders = $this->buildVaryRequestHeaders($request);

        $this->requestTime = $requestTime;
        $this->responseTime = $responseTime;

        $this->bodyHash = $bodyHash;
    }

    /**
     * @return int
     *
     * @see https://tools.ietf.org/html/rfc7234.html#section-4.2.1
     */
    public function getFreshnessLifetime(): int
    {
        $cacheControl = parseCacheControlHeader($this);

        if (isset($cacheControl[ResponseCacheControl::MAX_AGE])) {
            return $cacheControl[ResponseCacheControl::MAX_AGE];
        }

        if ($this->hasHeader('expires')) {
            $headerCount = \count($this->getHeaderArray('expires'));
            if ($headerCount > 1) {
                return 0; // treat as expired
            }

            $expires = parseExpiresHeader($this->getHeader('expires'));
            $date = parseDateHeader($this->getHeader('date'));

            if ($date === null) {
                return 0; // treat as expired
            }

            return \max(0, $expires->getTimestamp() - $date->getTimestamp());
        }

        return 0; // treat as expired, we don't implement heuristic freshness for now
    }

    /**
     * @return int
     *
     * @see https://tools.ietf.org/html/rfc7234.html#section-4.2.3
     */
    public function getAge(): int
    {
        $date = parseDateHeader($this->getHeader('date'));
        if ($date === null) {
            /** @noinspection PhpUndefinedClassInspection */
            throw new \AssertionError('Got a cached response without date header, which should never happen. Please report this as a bug.');
        }

        $ageValue = (int) ($this->getHeader('age') ?? '0');

        $apparentAge = \max(0, $this->getResponseTime()->getTimestamp() - $date->getTimestamp());

        $responseDelay = $this->getResponseTime()->getTimestamp() - $this->getResponseTime()->getTimestamp();
        $correctedAgeValue = $ageValue + $responseDelay;

        $correctedInitialAge = \max($apparentAge, $correctedAgeValue);

        $residentTime = now()->getTimestamp() - $this->getResponseTime()->getTimestamp();

        return $correctedInitialAge + $residentTime;
    }

    public function toCacheData(): string
    {
        return \json_encode([
            'protocol_version' => $this->protocolVersion,
            'status' => $this->status,
            'reason' => $this->reason,
            'headers' => $this->getHeaders(),
            'request_method' => $this->requestMethod,
            'request_target' => $this->requestTarget,
            'request_vary' => $this->requestHeaders,
            'request_time' => $this->requestTime->getTimestamp(),
            'response_time' => $this->responseTime->getTimestamp(),
            'body_hash' => $this->bodyHash,
        ]);
    }

    /**
     * @return \DateTimeImmutable The current value of the clock at the host at the time the request resulting in the
     *     stored response was made.
     *
     * @see https://tools.ietf.org/html/rfc7234.html#section-4.2.3
     */
    public function getRequestTime(): \DateTimeImmutable
    {
        return $this->requestTime;
    }

    /**
     * @return \DateTimeImmutable The current value of the clock at the host at the time the response was received.
     *
     * @see https://tools.ietf.org/html/rfc7234.html#section-4.2.3
     */
    public function getResponseTime(): \DateTimeImmutable
    {
        return $this->responseTime;
    }

    public function matches(Request $request): bool
    {
        $requestMethod = $request->getMethod();
        $requestTarget = (string) $request->getUri();

        if ($requestMethod !== $this->requestMethod) {
            return false;
        }

        if ($requestTarget !== $this->requestTarget) {
            return false;
        }

        if (!$this->hasHeader('vary')) {
            return true;
        }

        $varyHeaders = createFieldValueComponentMap(parseFieldValueComponents($this, 'vary'));

        if (isset($varyHeaders['*'])) {
            return false;  // 'A Vary header field-value of "*" always fails to match.'
        }

        foreach ($varyHeaders as $varyHeader) {
            if ($request->getHeaderArray($varyHeader) !== ($this->requestHeaders[$varyHeader] ?? [])) {
                return false;
            }
        }

        return true;
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function getProtocolVersion(): string
    {
        return $this->protocolVersion;
    }

    public function getBodyHash(): string
    {
        return $this->bodyHash;
    }

    /**
     * @return bool
     *
     * @see https://tools.ietf.org/html/rfc7234.html#section-5.3
     */
    public function isStale(): bool
    {
        return !$this->isFresh();
    }

    /**
     * @return bool
     *
     * @see https://tools.ietf.org/html/rfc7234.html#section-5.3
     */
    public function isFresh(): bool
    {
        return $this->getFreshnessLifetime() > $this->getAge();
    }

    private function buildVaryRequestHeaders(Request $request): array
    {
        $varyHeaders = createFieldValueComponentMap(parseFieldValueComponents($this, 'vary'));
        $requestHeaders = [];

        foreach ($varyHeaders as $varyHeader => $_) {
            $requestHeaders[$varyHeader] = $request->getHeaderArray($varyHeader);
        }

        return $requestHeaders;
    }
}
