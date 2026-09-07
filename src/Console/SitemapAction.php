<?php

declare(strict_types=1);

namespace IndexNowKit\Yii2\Console;

use IndexNowKit\Adapter\SubmitterFactoryInterface;
use IndexNowKit\Console\CommandDefinition;
use IndexNowKit\Console\ResultFormatterInterface;
use IndexNowKit\Sitemap\Adapter\SitemapServices;
use IndexNowKit\Sitemap\Console\Definitions;
use IndexNowKit\Sitemap\Console\SitemapOptions;
use IndexNowKit\Yii2\IndexNowComponent;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The body of `php yii indexnow/sitemap`: the only part of the controller that reads `IndexNowKit\Sitemap\*`,
 * called by {@see IndexNowController} only when `indexnowkit/sitemap` is installed.
 */
final class SitemapAction
{
    /** The inputs of the action, from the sitemap package's definitions. */
    public static function definition(): CommandDefinition
    {
        return Definitions::sitemap();
    }

    public static function run(IndexNowComponent $component, SymfonyStyle $io, SubmitterFactoryInterface $submitters, ResultFormatterInterface $formatter, ?string $sitemap, ?string $changedSince, bool $allowForeignHosts, bool $force, bool $dryRun, bool $json, bool $noVerify = false): int
    {
        // `sitemap.enabled: false` is the runner's answer (`sitemap.enabled is false.`, exit 2), as in every adapter
        $runner = SitemapServices::runner($component->kit(), $component->sitemapSource(), $submitters, $component->sitemapConfig(), $formatter, 'sitemap.url', $component->unverifiedSubmitterFactory(), $component->services()->clock());

        return $runner->run($io, new SitemapOptions($sitemap, $changedSince, $allowForeignHosts, $force, $dryRun, $json, $noVerify));
    }
}
