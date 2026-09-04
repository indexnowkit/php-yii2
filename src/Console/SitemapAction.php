<?php

declare(strict_types=1);

namespace IndexNowKit\Yii2\Console;

use IndexNowKit\Console\CommandDefinition;
use IndexNowKit\Console\ExitCode;
use IndexNowKit\Console\ResultFormatterInterface;
use IndexNowKit\Console\SubmitterFactoryInterface;
use IndexNowKit\Sitemap\Console\Definitions;
use IndexNowKit\Sitemap\Console\SitemapOptions;
use IndexNowKit\Sitemap\Console\SitemapRunner;
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

    public static function run(IndexNowComponent $component, SymfonyStyle $io, SubmitterFactoryInterface $submitters, ResultFormatterInterface $formatter, ?string $sitemap, ?string $changedSince, bool $allowForeignHosts, bool $force, bool $dryRun, bool $json): int
    {
        $config = $component->sitemapConfig();
        if (!$config->enabled) {
            $io->error('sitemap.enabled is false.');

            return ExitCode::INVALID;
        }
        $runner = new SitemapRunner($component->kit(), $component->sitemapSource(), $submitters, $config->url, $formatter, sitemapUrlOption: 'sitemap.url');

        return $runner->run($io, new SitemapOptions($sitemap, $changedSince, $allowForeignHosts, $force, $dryRun, $json));
    }
}
