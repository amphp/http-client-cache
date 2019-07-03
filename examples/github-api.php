<?php

use Amp\Cache\ArrayCache;
use Amp\Delayed;
use Amp\Http\Client\Cache\Cache;
use Amp\Http\Client\ClientBuilder;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use Amp\Http\Rfc7230;
use Amp\Loop;

require __DIR__ . '/../vendor/autoload.php';

Loop::run(static function () {
    $cache = new ArrayCache;

    $client = (new ClientBuilder)
        ->addApplicationInterceptor(new Cache($cache))
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