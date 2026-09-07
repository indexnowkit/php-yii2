<?php

declare(strict_types=1);

namespace IndexNowKit\Yii2\Config;

use IndexNowKit\Adapter\ConfigFactory as CoreConfigFactory;
use IndexNowKit\Adapter\OptionalPackage;
use IndexNowKit\Config;
use IndexNowKit\Dispatch\DispatcherFactory;
use IndexNowKit\Exception\ConfigurationException;
use IndexNowKit\History\Adapter\HistoryServices;
use IndexNowKit\Sitemap\Adapter\SitemapServices;
use IndexNowKit\Verify\Adapter\VerifyServices;
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
     * Keys this package owns on top of Config::OPTIONS (and SitemapConfig::OPTIONS when indexnowkit/sitemap is
     * installed), dotted-path form only: a bare block name in this list would stop unknownOptions() from checking
     * the keys inside the block.
     */
    public const YII_OPTIONS = [
        'queue.component', 'queue.ttr', 'queue.delay', 'queue.priority',
        'key_file.pattern',
        'router.locales', 'router.locale_parameter', 'router.set_app_locale',
        'router.languages', 'router.language_parameter', 'router.set_app_language',   // deprecated spellings (0.12.0), still read
        'active_record.enabled', 'active_record.models',
        'logging.category',
    ];

    public const DISPATCH_AUTO = 'auto';

    public const DISPATCH_QUEUE = 'queue';

    public const DISPATCH_MODES = [self::DISPATCH_QUEUE, DispatcherFactory::SYNC, DispatcherFactory::NONE];

    /**
     * Without indexnowkit/sitemap the `sitemap` block is ignored as a whole (no "unknown option" warning for options
     * written for the package); with it, its keys are owned and typos inside it are warned about.
     *
     * @param array<string, mixed> $options          the component's `options`
     * @param bool                 $queueExists      whether the configured queue component exists (resolves `dispatch: auto`)
     * @param bool|null            $sitemapInstalled null = detect (the core's `OptionalPackage::sitemap()`, which answers
     *                                               without the package); the component passes its `sitemapInstalled`
     *                                               property, tests pass false
     * @param bool|null            $verifyInstalled  the same for `indexnowkit/verify` (`OptionalPackage::verify()`)
     * @param bool|null            $historyInstalled the same for `indexnowkit/history` (`OptionalPackage::history()`)
     */
    public static function factory(array $options, bool $queueExists, ?bool $sitemapInstalled = null, ?bool $verifyInstalled = null, ?bool $historyInstalled = null): CoreConfigFactory
    {
        $queue = \is_array($options['queue'] ?? null) ? $options['queue'] : [];
        $component = \is_string($queue['component'] ?? null) && $queue['component'] !== '' ? $queue['component'] : 'queue';
        $sitemap = OptionalPackage::sitemap($sitemapInstalled)->installed();
        $verify = OptionalPackage::verify($verifyInstalled)->installed();
        $history = OptionalPackage::history($historyInstalled)->installed();

        return new CoreConfigFactory(
            ownedOptions: [...self::YII_OPTIONS, ...$sitemap ? SitemapServices::options() : [], ...$verify ? VerifyServices::options() : [], ...$history ? HistoryServices::options() : []],
            dispatchModes: self::DISPATCH_MODES,
            autoDispatch: static fn(): string => $queueExists ? self::DISPATCH_QUEUE : DispatcherFactory::SYNC,
            needBaseUrl: [self::DISPATCH_QUEUE],
            defaults: ['dispatch' => self::DISPATCH_AUTO],
            validate: static fn(Config $config): ?string => $config->dispatch === self::DISPATCH_QUEUE && !$queueExists
                ? \sprintf('"dispatch" is "queue" but the queue component "%s" is not configured (yiisoft/yii2-queue, option queue.component).', $component)
                : null,
            checkCommand: 'php yii indexnow/check',
            ignoreBlocks: [...$sitemap ? [] : ['sitemap'], ...$verify ? [] : ['verify'], ...$history ? [] : ['history']],
        );
    }

    /**
     * Runtime path: never throws.
     *
     * @param array<string, mixed> $options the component's `options`
     */
    public static function create(array $options, string $environment, bool $queueExists, ?LoggerInterface $logger = null, ?bool $sitemapInstalled = null, ?bool $verifyInstalled = null, ?bool $historyInstalled = null): Config
    {
        return self::factory($options, $queueExists, $sitemapInstalled, $verifyInstalled, $historyInstalled)->load($options, $environment, $logger ?? new NullLogger());
    }

    /**
     * Strict path (`indexnow/check`, tests).
     *
     * @param array<string, mixed> $options
     *
     * @throws ConfigurationException
     */
    public static function build(array $options, string $environment, bool $queueExists, ?bool $sitemapInstalled = null, ?bool $verifyInstalled = null, ?bool $historyInstalled = null): Config
    {
        return self::factory($options, $queueExists, $sitemapInstalled, $verifyInstalled, $historyInstalled)->build($options, $environment);
    }
}
