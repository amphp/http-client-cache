<?php declare(strict_types=1);

namespace Amp\Http\Client\Cache\Internal;

use Amp\Http\Client\Cache\ResponseCacheControl;
use Amp\Http\Client\HttpException;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use Amp\Http\HttpMessage;
use function Amp\Http\Client\Cache\now;
use function Amp\Http\Client\Cache\parseCacheControlHeader;
use function Amp\Http\Client\Cache\parseDateHeader;
use function Amp\Http\Client\Cache\parseExpiresHeader;
use function Amp\Http\splitHeader;

/**
 * @internal
 * @psalm-type ProtocolVersion = '1.0'|'1.1'|'2'
 */
final class CachedResponse extends HttpMessage
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

    private readonly string $requestMethod;
    private readonly string $requestTarget;
    private readonly array $requestHeaders;

    /**
     * @param ProtocolVersion $protocolVersion
     */
    private function __construct(
        private readonly string $protocolVersion,
        private readonly int $status,
        private readonly string $reason,
        array $headers,
        Request $request,
        private readonly \DateTimeImmutable $requestTime,
        private readonly \DateTimeImmutable $responseTime,
        private readonly string $bodyHash
    ) {
        /** @noinspection PhpUnhandledExceptionInspection */
        $this->setHeaders($headers);

        $this->requestMethod = $request->getMethod();
        $this->requestTarget = (string) $request->getUri();
        $this->requestHeaders = $this->buildVaryRequestHeaders($request);
    }

    /**
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

            /** @psalm-suppress PossiblyNullArgument */
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
     * @see https://tools.ietf.org/html/rfc7234.html#section-4.2.3
     */
    public function getAge(): int
    {
        $date = parseDateHeader($this->getHeader('date'));
        if ($date === null) {
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

        $varyHeaders = splitHeader($this, 'vary');
        if ($varyHeaders === null) {
            return false; // Invalid header?!
        }

        if (\in_array('*', $varyHeaders, true)) {
            return false;  // A Vary header field-value of "*" always fails to match.
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

    /** @return ProtocolVersion */
    public function getProtocolVersion(): string
    {
        return $this->protocolVersion;
    }

    public function getBodyHash(): string
    {
        return $this->bodyHash;
    }

    /**
     * @see https://tools.ietf.org/html/rfc7234.html#section-5.3
     */
    public function isStale(): bool
    {
        return !$this->isFresh();
    }

    /**
     * @see https://tools.ietf.org/html/rfc7234.html#section-5.3
     */
    public function isFresh(): bool
    {
        return $this->getFreshnessLifetime() > $this->getAge();
    }

    private function buildVaryRequestHeaders(Request $request): array
    {
        $varyHeaders = splitHeader($this, 'vary');
        \assert($varyHeaders !== null);

        $requestHeaders = [];

        foreach ($varyHeaders as $varyHeader) {
            $requestHeaders[$varyHeader] = $request->getHeaderArray($varyHeader);
        }

        return $requestHeaders;
    }
}
