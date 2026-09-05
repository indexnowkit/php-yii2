<?php

declare(strict_types=1);

namespace IndexNowKit\Yii2\Verify;

use Closure;
use IndexNowKit\Adapter\OptionalPackage;
use IndexNowKit\Adapter\Services;
use IndexNowKit\Adapter\SubmitterFactoryInterface;
use IndexNowKit\Check\CheckInterface;
use IndexNowKit\Check\CheckLevel;
use IndexNowKit\Check\CheckReport;
use IndexNowKit\Check\StaticCheck;
use IndexNowKit\Http\TransportFactory;
use IndexNowKit\Http\TransportInterface;
use IndexNowKit\SubmitterInterface;
use IndexNowKit\Verify\Check\SampleCheck;
use IndexNowKit\Verify\PageSignals;
use IndexNowKit\Verify\RobotsCache;
use IndexNowKit\Verify\VerifyConfig;
use IndexNowKit\Verify\VerifyingSubmitter;
use IndexNowKit\Verify\VerifyingSubmitterFactory;
use IndexNowKit\Yii2\Check\SampleOptions;
use IndexNowKit\Yii2\Check\VerifySampleCheck;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;

/**
 * The verify pieces of the component: the only wiring of the package that reads `IndexNowKit\Verify\*`, called only
 * when {@see package()} says the package is installed (`IndexNowComponent::verifyInstalled()`). With
 * `verify.enabled: true` {@see Wiring} puts the decorated submitter into the graph and the component hands the
 * commands a decorated submitter factory, so sync flushes, yii2-queue jobs and the commands verify.
 */
final class VerifyServices
{
    /**
     * The one predicate for `indexnowkit/verify` (safe to call without the package: `::class` on an absent class
     * is a string); null = detect, false = wire as if the package were absent (the component's `verifyInstalled`).
     */
    public static function package(?bool $installed = null): OptionalPackage
    {
        return new OptionalPackage('indexnowkit/verify', PageSignals::class, 'verify', $installed);
    }

    /**
     * The dotted keys of the `verify` block, for `Config\ConfigFactory` (`VerifyConfig::OPTIONS`).
     *
     * @return list<string>
     */
    public static function options(): array
    {
        return VerifyConfig::OPTIONS;
    }

    /**
     * The validated `verify` block; a broken value switches the pre-flight off with a critical log line.
     *
     * @param array<string, mixed> $block
     */
    public static function config(array $block, LoggerInterface $logger): VerifyConfig
    {
        return VerifyConfig::loadOrDisabled($block, $logger, 'php yii indexnow/check');
    }

    /** The pre-flight transport: `verify.timeout`, `verify.user_agent`, the application's `http.client`. */
    public static function transport(VerifyConfig $verify, Services $services, Closure $clientLocator): TransportInterface
    {
        return TransportFactory::lazy($verify->transportConfig($services->config), $clientLocator, ['User-Agent' => $verify->userAgent()]);
    }

    public static function robots(VerifyConfig $verify, Services $services, TransportInterface $transport): RobotsCache
    {
        return new RobotsCache($transport, $services->failureCache(), $services->config->debounceKeyPrefix, $verify->robotsCacheTtl, $services->logger);
    }

    /** The decorated submitter of the graph ({@see Wiring}). */
    public static function submitter(SubmitterInterface $inner, VerifyConfig $verify, Services $services, TransportInterface $transport, RobotsCache $robots, ?EventDispatcherInterface $events, bool $inWebRequest): SubmitterInterface
    {
        return new VerifyingSubmitter($inner, $transport, $verify, $services->keys(), $services->normalizer(), $services->logger, $events, $services->submissionStore(), $robots, null, $inWebRequest);
    }

    /** The decorated command submitter factory. */
    public static function submitterFactory(SubmitterFactoryInterface $inner, VerifyConfig $verify, Services $services, TransportInterface $transport, RobotsCache $robots, ?EventDispatcherInterface $events): SubmitterFactoryInterface
    {
        return new VerifyingSubmitterFactory($inner, $transport, $verify, $services->keys(), $services->normalizer(), $services->logger, $events, $services->submissionStore(), $robots);
    }

    /**
     * The `check` lines with the package: `verify.installed`, `verify.dispatch` (a warning with `dispatch: sync`),
     * `verify.sample` over the `--sample` / `--sample-class` values of the running command.
     *
     * @return list<CheckInterface>
     */
    public static function checks(VerifyConfig $verify, Services $services, TransportInterface $transport, RobotsCache $robots, SampleOptions $samples): array
    {
        $line = $verify->enabled
            ? \sprintf('verify: enabled (redirect: %s, non_canonical: %s, origin_error: %s)', $verify->redirect->value, $verify->nonCanonical->value, $verify->originError->value)
            : 'verify: installed, disabled (verify.enabled: false)';
        $warn = $verify->enabled && $services->config->dispatch === 'sync';

        return [
            new StaticCheck(CheckLevel::Ok, $line, self::package(true)->checkCode()),
            new class ($warn) implements CheckInterface {
                public function __construct(private readonly bool $warn) {}

                public function check(CheckReport $report): void
                {
                    if ($this->warn) {
                        $report->warning('verify: verify.enabled with dispatch: sync fetches your own pages inside the web request; use dispatch: queue', 'verify.dispatch');
                    }
                }
            },
            new VerifySampleCheck($samples, static function (array $urls, array $classes) use ($samples, $transport, $verify, $services, $robots): CheckInterface {
                /** @var list<string> $urls */
                /** @var list<string> $classes */
                return new SampleCheck($urls, $classes, $transport, $verify, $services->normalizer(), $services->keys(), $samples->sampler, $robots);
            }),
        ];
    }
}
