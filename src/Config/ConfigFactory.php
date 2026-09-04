<?php

declare(strict_types=1);

namespace IndexNowKit\Yii2\Config;

use IndexNowKit\Config;
use IndexNowKit\Exception\ConfigurationException;
use IndexNowKit\Sitemap\SitemapConfig;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Builds the runtime Config from the component's `options` array. Values usually come from getenv()/.env, so they
 * are only known at runtime: instead of throwing from an ActiveRecord event or the response, a broken value is
 * logged once at critical and IndexNow runs disabled until fixed. `php yii indexnow/check` prints the exact error.
 */
final class ConfigFactory
{
    /**
     * Keys this package owns on top of Config::OPTIONS and SitemapConfig::OPTIONS, dotted-path form only: a bare
     * block name in this list would stop unknownOptions() from checking the keys inside the block.
     */
    public const YII_OPTIONS = [
        'queue.component', 'queue.ttr', 'queue.delay', 'queue.priority',
        'key_file.enabled', 'key_file.pattern', 'key_file.cache_max_age',
        'router.languages', 'router.language_parameter', 'router.set_app_language',
        'active_record.enabled', 'active_record.models',
        'logging.category', 'debounce.store', 'http.client',
    ];

    public const DISPATCH_AUTO = 'auto';

    /**
     * @param array<string, mixed> $options      the component's `options`
     * @param bool                 $queueExists  whether the configured queue component exists (resolves `dispatch: auto`)
     */
    public static function create(array $options, string $environment, bool $queueExists, ?LoggerInterface $logger = null): Config
    {
        $logger ??= new NullLogger();
        try {
            $unknown = Config::unknownOptions($options, [...self::YII_OPTIONS, ...SitemapConfig::OPTIONS]);
            if ($unknown !== []) {
                $logger->warning('indexnow: unknown option(s) in the indexnow component: {options}', ['options' => implode(', ', $unknown)]);
            }

            return self::build($options, $environment, $queueExists);
        } catch (ConfigurationException $e) {
            $logger->critical('indexnow: invalid configuration, IndexNow is disabled until it is fixed: {error} (run "php yii indexnow/check")', ['error' => $e->getMessage(), 'exception' => $e]);

            return new Config(enabled: false, dryRun: true, environment: $environment);
        }
    }

    /**
     * @param array<string, mixed> $options
     *
     * @throws ConfigurationException
     */
    public static function build(array $options, string $environment, bool $queueExists): Config
    {
        $core = self::coreOptions($options);
        $core['environment'] ??= $environment;
        $dispatch = $core['dispatch'] ?? self::DISPATCH_AUTO;
        if ($dispatch === self::DISPATCH_AUTO) {
            $core['dispatch'] = $queueExists ? 'queue' : 'sync';
        }
        $built = Config::fromArray($core);
        if (!\in_array($built->dispatch, ['sync', 'queue', 'none'], true)) {
            throw new ConfigurationException(\sprintf('"dispatch" must be auto, sync, queue or none, got "%s".', $built->dispatch));
        }
        if ($built->dispatch === 'queue' && $built->baseUrl === null) {
            throw new ConfigurationException('"dispatch" is "queue" but "base_url" is not set: a queue worker has no request to take the host from.');
        }
        if ($built->dispatch === 'queue' && !$queueExists) {
            throw new ConfigurationException('"dispatch" is "queue" but the queue component does not exist (yiisoft/yii2-queue, option queue.component).');
        }

        return $built;
    }

    /**
     * Strips the Yii-only blocks and maps the `key_file.enabled` alias before handing the array to the core.
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public static function coreOptions(array $options): array
    {
        $keyFile = \is_array($options['key_file'] ?? null) ? $options['key_file'] : [];
        $serve = $options['serve_key_file'] ?? null;
        $options['serve_key_file'] = \is_bool($serve) ? $serve : (bool) ($keyFile['enabled'] ?? true);
        unset($options['queue'], $options['key_file'], $options['router'], $options['active_record'], $options['sitemap']);
        if (\is_array($options['logging'] ?? null)) {
            unset($options['logging']['category']);
        }
        if (\is_array($options['debounce'] ?? null)) {
            unset($options['debounce']['store']);
        }
        if (\is_array($options['http'] ?? null)) {
            unset($options['http']['client']);
        }

        return $options;
    }
}
