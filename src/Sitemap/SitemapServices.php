<?php

declare(strict_types=1);

namespace IndexNowKit\Yii2\Sitemap;

use IndexNowKit\Adapter\OptionalPackage;
use IndexNowKit\Check\CheckInterface;
use IndexNowKit\Http\TransportInterface;
use IndexNowKit\Sitemap\Check\SitemapSpoolCheck;
use IndexNowKit\Sitemap\SitemapConfig;
use IndexNowKit\Sitemap\SitemapReader;
use IndexNowKit\Sitemap\SitemapSourceInterface;
use Psr\Log\LoggerInterface;

/**
 * The sitemap pieces of the component: the only wiring of the package (with `Console\SitemapAction`) that reads
 * `IndexNowKit\Sitemap\*`, called only when {@see package()} says the package is installed
 * (`IndexNowComponent::sitemapInstalled()`).
 */
final class SitemapServices
{
    /**
     * The one predicate for `indexnowkit/sitemap` (safe to call without the package: `::class` on an absent class
     * is a string); null = detect, false = wire as if the package were absent (the component's `sitemapInstalled`).
     */
    public static function package(?bool $installed = null): OptionalPackage
    {
        return new OptionalPackage('indexnowkit/sitemap', SitemapReader::class, 'sitemap', $installed);
    }

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
        return SitemapConfig::loadOrDisabled($block, $logger, 'php yii indexnow/check');
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
