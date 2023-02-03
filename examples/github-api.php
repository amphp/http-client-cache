<?php

use Amp\Cache\LocalCache;
use Amp\Http\Client\Cache\SingleUserCache;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Amp\Log\ConsoleFormatter;
use Amp\Log\StreamHandler;
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;
use function Amp\ByteStream\getStdout;
use function Amp\delay;

require __DIR__ . '/../vendor/autoload.php';

$logFormatter = new ConsoleFormatter;
$logFormatter->allowInlineLineBreaks();
$logFormatter->ignoreEmptyContextAndExtra();

$streamHandler = new StreamHandler(getStdout());
$streamHandler->setFormatter($logFormatter);

$logger = new Logger('main');
$logger->pushProcessor(new PsrLogMessageProcessor);
$logger->pushHandler($streamHandler);

$cache = new LocalCache();

$client = (new HttpClientBuilder)
    ->intercept(new SingleUserCache($cache, $logger))
    ->build();

$response = $client->request(new Request('https://api.github.com/orgs/amphp'));
$requestId1 = $response->getHeader('x-github-request-id');
$logger->info('Received response: ' . $requestId1);
$response->getBody()->buffer();

$logger->info('Waiting 3 seconds before making another request...');
delay(3);

$response = $client->request(new Request('https://api.github.com/orgs/amphp'));
$requestId2 = $response->getHeader('x-github-request-id');
$logger->info('Received response: ' . $requestId2);
$response->getBody()->buffer();

if ($requestId1 === $requestId2) {
    $logger->info('Received the same request ID (cached response)');
} else {
    $logger->info('Received another request ID (non-cached response)');
}
