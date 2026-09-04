<?php

declare(strict_types=1);

namespace IndexNowKit\Yii2\Sitemap;

/**
 * Whether the optional `indexnowkit/sitemap` package is installed: the one predicate the component, the config
 * factory and the console controller share. Everything that reads `IndexNowKit\Sitemap\*` ({@see SitemapServices},
 * `Console\SitemapAction`) is used only when it holds; without the package `indexnow/sitemap` prints one sentence
 * and exits 1, `indexnow/check` prints one line, and nothing is logged.
 */
final class SitemapSupport
{
    /** What `indexnow/sitemap` prints, and the message of the LogicException of `sitemapConfig()` / `sitemapSource()`, without the package. */
    public const NOT_INSTALLED = 'indexnowkit/sitemap is not installed: composer require indexnowkit/sitemap';
    /** What `indexnow/check` prints without the package: no `sitemap` block in the options, or a block that is ignored. */
    public const CHECK_MISSING = 'sitemap: not installed (composer require indexnowkit/sitemap)';
    public const CHECK_MISSING_BLOCK_IGNORED = 'sitemap: not installed, the sitemap block in the configuration is ignored (composer require indexnowkit/sitemap)';

    /**
     * @internal tests only: force the answer (false = boot as if the package were absent); null = detect
     */
    public static ?bool $installed = null;

    public static function installed(): bool
    {
        return self::$installed ?? class_exists(\IndexNowKit\Sitemap\SitemapReader::class);
    }

    /**
     * @param array<string, mixed> $block the `sitemap` block of the options
     */
    public static function checkLine(array $block): string
    {
        return $block === [] ? self::CHECK_MISSING : self::CHECK_MISSING_BLOCK_IGNORED;
    }
}
