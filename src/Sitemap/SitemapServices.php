<?php

declare(strict_types=1);

namespace IndexNowKit\Yii2\Sitemap;

use IndexNowKit\Check\CheckInterface;
use IndexNowKit\Exception\ConfigurationException;
use IndexNowKit\Http\TransportInterface;
use IndexNowKit\Sitemap\Check\SitemapSpoolCheck;
use IndexNowKit\Sitemap\SitemapConfig;
use IndexNowKit\Sitemap\SitemapReader;
use IndexNowKit\Sitemap\SitemapSourceInterface;
use Psr\Log\LoggerInterface;

/**
 * The sitemap pieces of the component: the only wiring of the package (with `Console\SitemapAction`) that reads
 * `IndexNowKit\Sitemap\*`, called only when {@see SitemapSupport::installed()} holds.
 */
final class SitemapServices
{
    /**
     * The dotted keys of the `sitemap` block, for `Config\ConfigFactory` (`SitemapConfig::OPTIONS`).
     *
     * @return list<string>
     */
    public static function options(): array
    {
        return SitemapConfig::OPTIONS;
    }

    /**
     * The validated `sitemap` block; a broken value disables the sitemap command with a critical log line, like the
     * core options.
     *
     * @param array<string, mixed> $block
     */
    public static function config(array $block, LoggerInterface $logger): SitemapConfig
    {
        try {
            return SitemapConfig::fromArray($block);
        } catch (ConfigurationException $e) {
            $logger->critical('indexnow: invalid sitemap configuration, the sitemap command is disabled until it is fixed: {error}', ['error' => $e->getMessage(), 'exception' => $e]);

            return SitemapConfig::disabled();
        }
    }

    public static function reader(SitemapConfig $config, TransportInterface $transport, LoggerInterface $logger): SitemapSourceInterface
    {
        return SitemapReader::fromConfig($config, $transport, $logger);
    }

    public static function spoolCheck(SitemapConfig $config): CheckInterface
    {
        return new SitemapSpoolCheck($config);
    }
}
