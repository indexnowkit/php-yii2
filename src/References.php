<?php

declare(strict_types=1);

namespace IndexNowKit\Yii2;

use IndexNowKit\Exception\ConfigurationException;
use Throwable;
use yii\di\Instance;

/**
 * The overrides of {@see IndexNowComponent} (`transport`, `debounceStore`, `dispatcher`, `urlResolver`, `clock`,
 * `checks`) as `Instance::ensure()` takes them: an instance, a config array, a class name or a component id. A value
 * this cannot resolve is a mistake in the `indexnow` configuration, so it is a `ConfigurationException` of the family,
 * not Yii's `InvalidConfigException` (docs/adapters.md §15).
 */
final class References
{
    private function __construct() {}

    /**
     * @return array<string, mixed>|object|string what `Instance::ensure()` accepts
     *
     * @throws ConfigurationException when the value is none of those
     */
    public static function reference(mixed $value): array|object|string
    {
        if (\is_object($value) || \is_string($value)) {
            return $value;
        }
        if (\is_array($value)) {
            /** @var array<string, mixed> $value */
            return $value;
        }

        throw new ConfigurationException(\sprintf('indexnow: an override must be an instance, a config array, a class name or a component id, got %s.', get_debug_type($value)));
    }

    /**
     * A reference as the type it must be.
     *
     * @template T of object
     *
     * @param array<string, mixed>|object|string $reference
     * @param class-string<T>                    $type
     *
     * @return T
     *
     * @throws ConfigurationException when the reference names nothing, or names something of another type
     */
    public static function ensure(array|object|string $reference, string $type): object
    {
        try {
            $instance = Instance::ensure($reference, $type);
        } catch (Throwable $e) {
            throw new ConfigurationException(\sprintf('indexnow: %s is not a %s: %s', \is_string($reference) ? \sprintf('"%s"', $reference) : get_debug_type($reference), $type, $e->getMessage()), 0, $e);
        }
        \assert($instance instanceof $type);

        return $instance;
    }
}
