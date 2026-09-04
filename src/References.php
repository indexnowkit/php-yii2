<?php

declare(strict_types=1);

namespace IndexNowKit\Yii2;

use yii\base\InvalidConfigException;
use yii\di\Instance;

/**
 * The overrides of {@see IndexNowComponent} (`transport`, `debounceStore`, `dispatcher`, `urlResolver`, `checks`)
 * as `Instance::ensure()` takes them: an instance, a config array, a class name or a component id.
 */
final class References
{
    private function __construct() {}

    /**
     * @return array<string, mixed>|object|string what `Instance::ensure()` accepts, or an InvalidConfigException naming the value
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

        throw new InvalidConfigException(\sprintf('indexnow: an override must be an instance, a config array, a class name or a component id, got %s.', get_debug_type($value)));
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
     */
    public static function ensure(array|object|string $reference, string $type): object
    {
        $instance = Instance::ensure($reference, $type);
        \assert($instance instanceof $type);

        return $instance;
    }
}
