<?php

use Amp\Cache\ArrayCache;
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
use function Amp\getCurrentTime;

require __DIR__ . '/../vendor/autoload.php';

Loop::run(static function () use ($argv) {
    $logFormatter = new ConsoleFormatter;
    $logFormatter->allowInlineLineBreaks();
    $logFormatter->ignoreEmptyContextAndExtra();

    $streamHandler = new StreamHandler(getStdout());
    $streamHandler->setFormatter($logFormatter);

    $logger = new Logger('main');
    $logger->pushProcessor(new PsrLogMessageProcessor);
    $logger->pushHandler($streamHandler);

    $cache = new SingleUserCache(new ArrayCache, $logger);

    $pushDisabled = ($argv[1] ?? '') === '--disable-push';
    if ($pushDisabled) {
        $cache->setStorePushedResponses(false);
    }

    $client = (new HttpClientBuilder)
        ->intercept($cache)
        ->build();

    $start = getCurrentTime();

    /** @var Response $response */
    $response = yield $client->request(new Request('https://http2-server-push-demo.keksi.io/'));
    yield $response->getBody()->buffer();

    $response = yield $client->request(new Request('https://http2-server-push-demo.keksi.io/image.jpg'));
    yield $response->getBody()->buffer();

    $logger->info('Took {runtime} milliseconds' . ($pushDisabled ? ', run with --disable-push to compare' : ''), [
        'runtime' => getCurrentTime() - $start,
    ]);
});
