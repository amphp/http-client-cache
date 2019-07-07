<?php

namespace Amp\Http\Client\Cache;

use Amp\Http\Client\HttpException;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use Amp\Http\Message;
use function Amp\Http\createFieldValueComponentMap;
use function Amp\Http\parseFieldValueComponents;

final class CachedResponse extends Message
{
    public static function fromResponse(
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
            $response->getRequest(),
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

            $request = (new Request($data['request_target'], $data['request_method']))
                ->withHeaders($data['request_vary']);

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
