<?php

declare(strict_types=1);

namespace IndexNowKit\Yii2\Console;

use IndexNowKit\Console\CommandDefinition;
use IndexNowKit\History\Console\Definitions;
use IndexNowKit\History\Console\HistoryOptions;
use IndexNowKit\Yii2\History\HistoryServices;
use IndexNowKit\Yii2\IndexNowComponent;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The bodies of `php yii indexnow/history` and `php yii indexnow/status`: the only part of the controller that reads
 * `IndexNowKit\History\*`, called by {@see IndexNowController} only when `indexnowkit/history` is installed.
 */
final class HistoryAction
{
    /** The inputs of `indexnow/history`, from the history package's definitions. */
    public static function definition(): CommandDefinition
    {
        return Definitions::history();
    }

    /** The inputs of `indexnow/status`. */
    public static function statusDefinition(): CommandDefinition
    {
        return Definitions::status();
    }

    /**
     * @param int|string       $limit as typed (`--limit=20`)
     * @param bool|string|null $purge null = no purge; true = `--purge` alone (history.retention_days); a string = that many days
     */
    public static function history(IndexNowComponent $component, SymfonyStyle $io, ?string $host, ?string $status, ?string $url, ?string $since, int|string $limit, bool $json, bool|string|null $purge): int
    {
        $runner = HistoryServices::historyRunner($component->historyConfig(), $component->services());

        return $runner->run($io, new HistoryOptions($host, $status, $url, $since, $limit, $json, $purge));
    }

    public static function status(IndexNowComponent $component, SymfonyStyle $io, bool $json): int
    {
        return HistoryServices::statusRunner($component, $component->services())->run($io, $json);
    }
}
