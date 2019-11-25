<?php

namespace Amp\Http\Client\Cache;

use Amp\Cache\Cache;
use Amp\File;
use Amp\File\Driver;
use Amp\Promise;
use Amp\Sync\KeyedMutex;
use Amp\Sync\Lock;
use function Amp\call;

final class FileCache implements Cache
{
    private $directory;
    private $mutex;

    public function __construct(string $directory, KeyedMutex $mutex)
    {
        $this->directory = \rtrim($directory, "/\\");
        $this->mutex = $mutex;

        if (!\interface_exists(Driver::class)) {
            throw new \Error('FileCache requires amphp/file to be installed');
        }
    }

    public function get(string $key): Promise
    {
        return call(function () use ($key) {
            /** @var Lock $lock */
            $lock = yield $this->mutex->acquire($key);

            try {
                $cacheContent = yield File\get($this->directory . '/' . \hash('sha256', $key) . '.cache');

                $ttl = \unpack('Nttl', \substr($cacheContent, 0, 4))['ttl'];
                if ($ttl < \time()) {
                    yield File\unlink($this->directory . '/' . \hash('sha256', $key) . '.cache');

                    return null;
                }

                return \substr($cacheContent, 4);
            } catch (\Throwable $e) {
                return null;
            } finally {
                $lock->release();
            }
        });
    }

    public function set(string $key, string $value, int $ttl = null): Promise
    {
        return call(function () use ($key, $value, $ttl) {
            if ($ttl < 0) {
                throw new \Error("Invalid cache TTL ({$ttl}); integer >= 0 or null required");
            }

            /** @var Lock $lock */
            $lock = yield $this->mutex->acquire($key);

            if ($ttl === null) {
                $ttl = \PHP_INT_MAX;
            } else {
                $ttl = \time() + $ttl;
            }

            $encodedTtl = \pack('N', $ttl);

            try {
                return yield File\put($this->directory . '/' . \hash('sha256', $key) . '.cache', $encodedTtl . $value);
            } finally {
                $lock->release();
            }
        });
    }

    public function delete(string $key): Promise
    {
        return call(function () use ($key) {
            /** @var Lock $lock */
            $lock = yield $this->mutex->acquire($key);

            try {
                return yield File\unlink($this->directory . '/' . \hash('sha256', $key) . '.cache');
            } finally {
                $lock->release();
            }
        });
    }
}
