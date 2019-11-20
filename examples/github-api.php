<?php

use Amp\Cache\ArrayCache;
use Amp\Delayed;
use Amp\Http\Client\Cache\SingleUserCache;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use Amp\Log\ConsoleFormatter;
use Amp\Log\StreamHandler;
use Amp\Loop;
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;
use function Amp\ByteStream\getStdout;

require __DIR__ . '/../vendor/autoload.php';

Loop::run(static function () {
    $logFormatter = new ConsoleFormatter;
    $logFormatter->allowInlineLineBreaks();
    $logFormatter->ignoreEmptyContextAndExtra();

    $streamHandler = new StreamHandler(getStdout());
    $streamHandler->setFormatter($logFormatter);

    $logger = new Logger('main');
    $logger->pushProcessor(new PsrLogMessageProcessor);
    $logger->pushHandler($streamHandler);

    $cache = new ArrayCache;

    $client = (new HttpClientBuilder)
        ->intercept(new SingleUserCache($cache, $logger))
        ->build();

    /** @var Response $response */
    $response = yield $client->request(new Request('https://api.github.com/users/kelunik'));
    $requestId1 = $response->getHeader('x-github-request-id');
    $logger->info('Received response: ' . $requestId1);
    yield $response->getBody()->buffer();

    $logger->info('Waiting 3000 milliseconds before making another request...');
    yield new Delayed(3000);

    /** @var Response $response */
    $response = yield $client->request(new Request('https://api.github.com/users/kelunik'));
    $requestId2 = $response->getHeader('x-github-request-id');
    $logger->info('Received response: ' . $requestId2);
    yield $response->getBody()->buffer();

    if ($requestId1 === $requestId2) {
        $logger->info('Received the same request ID (cached response)');
    } else {
        $logger->info('Received another request ID (non-cached response)');
    }
});
