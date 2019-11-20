<?php

namespace Amp\Http\Client\Cache;

use Amp\ByteStream\InMemoryStream;
use Amp\Cache\NullCache;
use Amp\CancellationToken;
use Amp\Http\Client\ApplicationInterceptor;
use Amp\Http\Client\DelegateHttpClient;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use Amp\PHPUnit\AsyncTestCase;
use Amp\Promise;
use Amp\Success;
use function Amp\call;

class PrivateCacheTest extends AsyncTestCase
{
    /** @var Request */
    private $request;

    /** @var Response */
    private $response;

    /** @var PrivateCache */
    private $cache;

    /** @var int */
    private $clientCallCount;

    public function testFreshResponse(): \Generator
    {
        yield $this->whenRequestIsExecuted();

        $this->thenClientCallCountIsEqualTo(1);
        $this->thenResponseDoesNotContainHeader('age');
    }

    public function testOnlyIfCached(): \Generator
    {
        $this->givenRequestHeader('cache-control', 'only-if-cached');

        yield $this->whenRequestIsExecuted();

        $this->thenClientCallCountIsEqualTo(0);
        $this->thenResponseDoesNotContainHeader('age');
        $this->thenResponseCodeIsEqualTo(504);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->cache = new PrivateCache(new NullCache);
        $this->clientCallCount = 0;

        $this->request = new Request('https://example.org/');
    }

    private function whenRequestIsExecuted(): Promise
    {
        return call(function () {
            $clientCallCount = &$this->clientCallCount;

            $countingInterceptor = new class($clientCallCount) implements ApplicationInterceptor {
                private $clientCallCount;

                public function __construct(int &$clientCallCount)
                {
                    $this->clientCallCount = &$clientCallCount;
                }

                public function request(Request $request, CancellationToken $cancellation, DelegateHttpClient $client): Promise
                {
                    $this->clientCallCount++;

                    return new Success(new Response(
                        '1.1',
                        200,
                        'OK',
                        [],
                        new InMemoryStream('hello'),
                        $request
                    ));
                }
            };

            $client = (new HttpClientBuilder)
                ->intercept($this->cache)
                ->intercept($countingInterceptor)
                ->build();

            $response = yield $client->request($this->request);

            $this->response = $response;
        });
    }

    private function thenResponseDoesNotContainHeader(string $field): void
    {
        self::assertFalse($this->response->hasHeader($field));
    }

    private function thenClientCallCountIsEqualTo(int $count): void
    {
        self::assertSame($count, $this->clientCallCount);
    }

    private function givenRequestHeader(string $field, string $value): void
    {
        $this->request->setHeader($field, $value);
    }

    private function thenResponseCodeIsEqualTo(int $code): void
    {
        self::assertSame($code, $this->response->getStatus());
    }
}
