# http-client-cache

[![Build Status](https://img.shields.io/travis/amphp/http-client-cache/master.svg?style=flat-square)](https://travis-ci.org/amphp/http-client-cache)
[![CoverageStatus](https://img.shields.io/coveralls/amphp/http-client-cache/master.svg?style=flat-square)](https://coveralls.io/github/amphp/http-client-cache?branch=master)
![License](https://img.shields.io/badge/license-MIT-blue.svg?style=flat-square)

This package provides an HTTP cache in form of an `ApplicationInterceptor` for [Amp's HTTP client](https://github.com/amphp/http-client) based on [RFC 7234](https://tools.ietf.org/html/rfc7234.html).

## Features

 - Conditional requests (planned)
 - Private cache
 - Shared cache (planned)

## Installation

This package can be installed as a [Composer](https://getcomposer.org/) dependency.

```bash
composer require amphp/http-client-cache
```

## Usage

```php
use Amp\Cache\FileCache;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Cache\SingleUserCache;
use Amp\Sync\LocalKeyedMutex;

$cache = new FileCache(__DIR__, new LocalKeyedMutex);

$client = (new HttpClientBuilder)
    ->intercept(new SingleUserCache($cache, $logger))
    ->build();
```

## Examples

More extensive code examples reside in the [`examples`](./examples) directory.

## Versioning

`amphp/http-client` follows the [semver](http://semver.org/) semantic versioning specification like all other `amphp` packages.

## Security

If you discover any security related issues, please email [`me@kelunik.com`](mailto:me@kelunik.com) instead of using the issue tracker.

## License

The MIT License (MIT). Please see [`LICENSE`](./LICENSE) for more information.
