<?php

namespace Amp\Http\Client\Cache;

use Amp\Http\Client\HttpException;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use Amp\Http\Message;

final class CachedResponse extends Message
{
    public static function fromResponse(
        Response $response,
        \DateTimeImmutable $requestTime,
        \DateTimeImmutable $responseTime,
        string $varyHash
    ): self {
        return new self(
            $response->getProtocolVersion(),
            $response->getStatus(),
            $response->getReason(),
            $response->getHeaders(),
            $response->getRequest(),
            $requestTime,
            $responseTime,
            $varyHash
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
            $data = \json_decode($data, true, 4, \JSON_THROW_ON_ERROR);

            if (!isset(
                $data['protocol_version'],
                $data['status'],
                $data['reason'],
                $data['headers'],
                $data['request_method'],
                $data['request_target'],
                $data['request_headers'],
                $data['request_time'],
                $data['response_time'],
                $data['vary_hash']
            )) {
                throw new HttpException('Failed to decode cached data, expected key not present');
            }

            $request = (new Request($data['request_target'], $data['request_method']))
                ->withHeaders($data['request_headers']);

            return new self(
                $data['protocol_version'],
                $data['status'],
                $data['reason'],
                $data['headers'],
                $request,
                new \DateTimeImmutable('@' . $data['request_time'], new \DateTimeZone('UTC')),
                new \DateTimeImmutable('@' . $data['response_time'], new \DateTimeZone('UTC')),
                $data['vary_hash']
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
    /** @var Request */
    private $request;

    /** @var string */
    private $varyHash;

    private function __construct(
        string $protocolVersion,
        int $status,
        string $reason,
        array $headers,
        Request $request,
        \DateTimeImmutable $requestTime,
        \DateTimeImmutable $responseTime,
        string $varyHash
    ) {
        $this->protocolVersion = $protocolVersion;
        $this->status = $status;
        $this->reason = $reason;
        $this->request = $request;

        /** @noinspection PhpUnhandledExceptionInspection */
        $this->setHeaders($headers);

        $this->requestTime = $requestTime;
        $this->responseTime = $responseTime;

        $this->varyHash = $varyHash;
    }

    public function toCacheData(): string
    {
        return \json_encode([
            'protocol_version' => $this->protocolVersion,
            'status' => $this->status,
            'reason' => $this->reason,
            'headers' => $this->getHeaders(),
            'request_method' => $this->request->getMethod(),
            'request_target' => (string) $this->request->getUri(),
            'request_headers' => $this->request->getHeaders(),
            'request_time' => $this->requestTime->getTimestamp(),
            'response_time' => $this->responseTime->getTimestamp(),
            'vary_hash' => $this->varyHash,
        ], \JSON_THROW_ON_ERROR);
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

    public function getRequest(): Request
    {
        return $this->request;
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

    public function getVaryHash(): string
    {
        return $this->varyHash;
    }
}
