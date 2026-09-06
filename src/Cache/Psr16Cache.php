<?php

declare(strict_types=1);

namespace IndexNowKit\Yii2\Cache;

use DateInterval;
use DateTimeImmutable;
use Psr\SimpleCache\CacheInterface;
use yii\caching\CacheInterface as YiiCache;

/**
 * A `yii\caching\CacheInterface` component as PSR-16, for the pieces of the core that take a PSR-16 cache (the 403
 * counter of `Client`). Yii's cache has no atomic increment, so the counter is approximate there, as documented on
 * `Client::__construct()`. Keys are passed through: the core builds them from `debounce.key_prefix` without the
 * characters PSR-6 reserves.
 */
final class Psr16Cache implements CacheInterface
{
    /** PSR-16: a key is up to 64 characters without `{}()/\@:`. */
    private const KEY = '/^[^{}()\/\\@:]{1,64}$/';

    public function __construct(private readonly YiiCache $cache) {}

    /** @param string $key */
    public function get($key, $default = null): mixed
    {
        $value = $this->cache->get(self::validKey($key));

        return \is_array($value) && \array_key_exists('v', $value) && \count($value) === 1 ? $value['v'] : $default;
    }

    /**
     * @param string $key
     * @param mixed  $ttl null|int|DateInterval
     */
    public function set($key, $value, $ttl = null): bool
    {
        return $this->cache->set(self::validKey($key), ['v' => $value], self::seconds($ttl)); // Yii signals a miss with false: an envelope keeps a stored false apart from it
    }

    /** @param string $key */
    public function delete($key): bool
    {
        return $this->cache->delete(self::validKey($key));
    }

    public function clear(): bool
    {
        return $this->cache->flush();
    }

    /**
     * @param iterable<mixed> $keys
     *
     * @return iterable<string, mixed>
     */
    public function getMultiple($keys, $default = null): iterable
    {
        $list = [];
        foreach ($keys as $key) {
            $list[] = self::validKey((string) $key); // @phpstan-ignore cast.string
        }
        $values = $this->cache->multiGet($list);
        $out = [];
        foreach ($list as $key) {
            $value = $values[$key] ?? false;
            $out[$key] = \is_array($value) && \array_key_exists('v', $value) && \count($value) === 1 ? $value['v'] : $default;
        }

        return $out;
    }

    /**
     * @param iterable<mixed, mixed> $values
     * @param mixed                  $ttl
     */
    public function setMultiple($values, $ttl = null): bool
    {
        $items = [];
        foreach ($values as $key => $value) {
            $items[self::validKey((string) $key)] = ['v' => $value]; // @phpstan-ignore cast.string
        }

        return $this->cache->multiSet($items, self::seconds($ttl)) === [];
    }

    /** @param iterable<mixed> $keys */
    public function deleteMultiple($keys): bool
    {
        $ok = true;
        foreach ($keys as $key) {
            $ok = $this->cache->delete(self::validKey((string) $key)) && $ok; // @phpstan-ignore cast.string
        }

        return $ok;
    }

    /** @param string $key */
    public function has($key): bool
    {
        return $this->cache->exists(self::validKey($key));
    }

    /**
     * @throws InvalidKey when $key is not a PSR-16 key
     */
    private static function validKey(string $key): string
    {
        if (preg_match(self::KEY, $key) !== 1) {
            throw new InvalidKey(\sprintf('"%s" is not a valid PSR-16 cache key (1-64 characters, none of {}()/\\@:).', $key));
        }

        return $key;
    }

    /** Yii's duration: 0 = never expires, which is also what a null PSR-16 TTL means. */
    private static function seconds(mixed $ttl): int
    {
        if ($ttl instanceof DateInterval) {
            $now = new DateTimeImmutable();

            return max(0, $now->add($ttl)->getTimestamp() - $now->getTimestamp());
        }

        return \is_int($ttl) ? $ttl : 0;
    }
}
