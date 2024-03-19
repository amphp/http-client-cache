# http-client-cache

![License](https://img.shields.io/badge/license-MIT-blue.svg?style=flat-square)

This package provides an HTTP cache in form of an `ApplicationInterceptor` for [Amp's HTTP client](https://github.com/amphp/http-client) based on [RFC 7234](https://tools.ietf.org/html/rfc7234.html).

## Features

 - Private cache (`SingleUserCache`)
 - Automatic `vary` header support
 - Caching pushed responses

## Planned Features

 - Shared cache
 - Conditional requests

## Installation

This package can be installed as a [Composer](https://getcomposer.org/) dependency.

```bash
composer require amphp/http-client-cache
```

## Usage

Currently, only a `SingleUserCache` is provided.
Therefore it is unsafe to use a single instance for multiple users, e.g. different access tokens.

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

`amphp/http-client-cache` follows the [semver](http://semver.org/) semantic versioning specification like all other `amphp` packages.

## Security

If you discover any security related issues, please email [`me@kelunik.com`](mailto:me@kelunik.com) instead of using the issue tracker.

## License

The MIT License (MIT). Please see [`LICENSE`](./LICENSE) for more information.
