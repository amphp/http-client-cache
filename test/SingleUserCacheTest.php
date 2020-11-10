<?php

namespace Amp\Http\Client\Cache;

use Amp\ByteStream\InMemoryStream;
use Amp\Cache\ArrayCache;
use Amp\CancellationToken;
use Amp\Http\Client\ApplicationInterceptor;
use Amp\Http\Client\DelegateHttpClient;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use Amp\PHPUnit\AsyncTestCase;
use function Amp\delay;

class SingleUserCacheTest extends AsyncTestCase
{
    private Request $request;

    private Response $response;

    private SingleUserCache $cache;

    private int $clientCallCount;

    private string $responseBody = 'hello';

    public function testFreshResponse(): void
    {
        $this->whenRequestIsExecuted();

        $this->thenClientCallCountIsEqualTo(1);
        $this->thenResponseDoesNotContainHeader('age');
    }

    public function testCachedResponse(): void
    {
        $this->whenRequestIsExecuted();

        $this->thenClientCallCountIsEqualTo(1);
        $this->thenResponseCodeIsEqualTo(200);
        $this->thenResponseBodyIs($this->responseBody);

        $this->whenRequestIsExecuted();

        $this->thenClientCallCountIsEqualTo(1);
        $this->thenResponseContainsHeader('age');
        $this->thenResponseBodyIs($this->responseBody);
    }

    public function testLargeResponseNotCached(): void
    {
        $this->givenResponseBodySize(1024 * 1024 + 1);

        $this->whenRequestIsExecuted();

        $this->thenClientCallCountIsEqualTo(1);
        $this->thenResponseCodeIsEqualTo(200);

        $this->whenRequestIsExecuted();

        $this->thenClientCallCountIsEqualTo(2);
        $this->thenResponseDoesNotContainHeader('age');
    }

    public function testVeryLargeResponseBodyConsumedFine(): void
    {
        $this->givenResponseBodySize(1024 * 1024 * 2);

        $this->whenRequestIsExecuted();

        $this->thenResponseBodyIs($this->responseBody);
    }

    public function testOnlyIfCached(): void
    {
        $this->givenRequestHeader('cache-control', 'only-if-cached');

        $this->whenRequestIsExecuted();

        $this->thenClientCallCountIsEqualTo(0);
        $this->thenResponseDoesNotContainHeader('age');
        $this->thenResponseCodeIsEqualTo(504);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->cache = new SingleUserCache(new ArrayCache);
        $this->clientCallCount = 0;

        $this->request = new Request('https://example.org/');
    }

    protected function cleanup(): void
    {
        parent::cleanup();
        delay(5); // Tick the event loop a few times to clean out watchers.
    }

    private function whenRequestIsExecuted(): void
    {
        $clientCallCount = &$this->clientCallCount;

        $countingInterceptor = new class($clientCallCount, $this->responseBody) implements ApplicationInterceptor {
            private int $clientCallCount;
            private string $responseBody;

            public function __construct(int &$clientCallCount, string $responseBody)
            {
                $this->clientCallCount = &$clientCallCount;
                $this->responseBody = $responseBody;
            }

            public function request(
                Request $request,
                CancellationToken $cancellation,
                DelegateHttpClient $client
            ): Response {
                $this->clientCallCount++;

                return new Response(
                    '1.1',
                    200,
                    'OK',
                    ['cache-control' => 'max-age=60'],
                    new InMemoryStream($this->responseBody),
                    $request
                );
            }
        };

        $client = (new HttpClientBuilder)
            ->intercept($this->cache)
            ->intercept($countingInterceptor)
            ->build();

        $this->response = $client->request($this->request);
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

    private function thenResponseContainsHeader(string $field): void
    {
        self::assertTrue($this->response->hasHeader($field));
    }

    private function givenResponseBodySize(int $size): void
    {
        $this->responseBody = \str_repeat('.', $size);
    }

    private function thenResponseBodyIs(string $responseBody): void
    {
        self::assertSame($responseBody, $this->response->getBody()->buffer());
    }
}
