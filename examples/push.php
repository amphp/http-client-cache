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
use function Amp\now;

require __DIR__ . '/../vendor/autoload.php';

$logFormatter = new ConsoleFormatter;
$logFormatter->allowInlineLineBreaks();
$logFormatter->ignoreEmptyContextAndExtra();

$streamHandler = new StreamHandler(getStdout());
$streamHandler->setFormatter($logFormatter);

$logger = new Logger('main');
$logger->pushProcessor(new PsrLogMessageProcessor);
$logger->pushHandler($streamHandler);

$pushEnabled = ($argv[1] ?? '') === '--disable-push';

$cache = new SingleUserCache(new LocalCache(), $logger, storePushedResponses: $pushEnabled);

$client = (new HttpClientBuilder)
    ->intercept($cache)
    ->build();

$start = now();

$response = $client->request(new Request('https://http2-server-push-demo.keksi.io/'));
$response->getBody()->buffer();

$response = $client->request(new Request('https://http2-server-push-demo.keksi.io/image.jpg'));
$response->getBody()->buffer();

$logger->info('Took {runtime} seconds' . ($pushEnabled ? ', run with --disable-push to compare' : ''), [
    'runtime' => now() - $start,
]);
