<?php

declare(strict_types=1);

namespace IndexNowKit\Yii2\Config;

use IndexNowKit\Adapter\ConfigFactory as CoreConfigFactory;
use IndexNowKit\Config;
use IndexNowKit\Exception\ConfigurationException;
use IndexNowKit\Sitemap\SitemapConfig;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Builds the runtime Config from the component's `options` array: the core's `Adapter\ConfigFactory` declared for
 * Yii2. Values usually come from getenv()/.env, so they are only known at runtime: instead of throwing from an
 * ActiveRecord event or the response, a broken value is logged once at critical and IndexNow runs disabled until
 * fixed. `php yii indexnow/check` prints the exact error.
 */
final class ConfigFactory
{
    /**
     * Keys this package owns on top of Config::OPTIONS and SitemapConfig::OPTIONS, dotted-path form only: a bare
     * block name in this list would stop unknownOptions() from checking the keys inside the block.
     */
    public const YII_OPTIONS = [
        'queue.component', 'queue.ttr', 'queue.delay', 'queue.priority',
        'key_file.pattern',
        'router.languages', 'router.language_parameter', 'router.set_app_language',
        'active_record.enabled', 'active_record.models',
        'logging.category',
    ];

    public const DISPATCH_AUTO = 'auto';

    public const DISPATCH_MODES = ['queue', 'sync', 'none'];

    /**
     * @param array<string, mixed> $options     the component's `options`
     * @param bool                 $queueExists whether the configured queue component exists (resolves `dispatch: auto`)
     */
    public static function factory(array $options, bool $queueExists): CoreConfigFactory
    {
        $queue = \is_array($options['queue'] ?? null) ? $options['queue'] : [];
        $component = \is_string($queue['component'] ?? null) && $queue['component'] !== '' ? $queue['component'] : 'queue';

        return new CoreConfigFactory(
            ownedOptions: [...self::YII_OPTIONS, ...SitemapConfig::OPTIONS],
            dispatchModes: self::DISPATCH_MODES,
            autoDispatch: static fn(): string => $queueExists ? 'queue' : 'sync',
            needBaseUrl: ['queue'],
            defaults: ['dispatch' => self::DISPATCH_AUTO],
            validate: static fn(Config $config): ?string => $config->dispatch === 'queue' && !$queueExists
                ? \sprintf('"dispatch" is "queue" but the queue component "%s" is not configured (yiisoft/yii2-queue, option queue.component).', $component)
                : null,
            checkCommand: 'php yii indexnow/check',
        );
    }

    /**
     * Runtime path: never throws.
     *
     * @param array<string, mixed> $options the component's `options`
     */
    public static function create(array $options, string $environment, bool $queueExists, ?LoggerInterface $logger = null): Config
    {
        return self::factory($options, $queueExists)->load($options, $environment, $logger ?? new NullLogger());
    }

    /**
     * Strict path (`indexnow/check`, tests).
     *
     * @param array<string, mixed> $options
     *
     * @throws ConfigurationException
     */
    public static function build(array $options, string $environment, bool $queueExists): Config
    {
        return self::factory($options, $queueExists)->build($options, $environment);
    }
}
