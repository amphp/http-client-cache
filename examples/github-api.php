<?php

use Amp\Cache\ArrayCache;
use Amp\Delayed;
use Amp\Http\Client\Cache\PrivateCache;
use Amp\Http\Client\ClientBuilder;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use Amp\Http\Rfc7230;
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

    $client = (new ClientBuilder)
        ->addApplicationInterceptor(new PrivateCache($cache, $logger))
        ->build();

    /** @var Response $response */
    $response = yield $client->request(new Request('https://api.github.com/users/kelunik'));

    print Rfc7230::formatHeaders($response->getHeaders());
    yield $response->getBody()->buffer();

    print "\r\n\r\n";

    yield new Delayed(3000);

    /** @var Response $response */
    $response = yield $client->request(new Request('https://api.github.com/users/kelunik'));

    print Rfc7230::formatHeaders($response->getHeaders());
    yield $response->getBody()->buffer();
});