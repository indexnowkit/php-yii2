<?php

declare(strict_types=1);

namespace IndexNowKit\Yii2\Url;

use IndexNowKit\Config;
use IndexNowKit\Exception\ConfigurationException;
use IndexNowKit\Url\RouteOrigin;
use IndexNowKit\Url\RouteUrlResolverInterface;
use IndexNowKit\Yii2\App;
use Psr\Log\LoggerInterface;
use Throwable;
use yii\db\BaseActiveRecord;
use yii\web\UrlManager;

/**
 * Router bridge: `#[IndexNow(route: 'post/view', params: ['slug' => 'slug'])]` -> `UrlManager::createAbsoluteUrl(['post/view', 'slug' => ...])`.
 *
 * - A `self` parameter is the record's primary key value (Yii has no route model binding).
 * - In a console application UrlManager knows no host: hostInfo and baseUrl come from `base_url` (on a clone, the
 *   component is left untouched). Inside an HTTP request the current host stays, as UrlManager generates it.
 * - A rule with `host:` is generated on `hosts.<host>.base_url`, else `https://<host>`.
 * - `$locale` is passed as the `router.locale_parameter` GET parameter (`language` by default, the Yii convention), and
 *   `Yii::$app->language` is switched for the duration when `router.set_app_locale` is on.
 *
 * What every bridge of the family decides the same way (the locale expansion and its one warning per process, the
 * pinned origin, the rebase, the exceptions) is the core's `Url\RouteOrigin`.
 */
final class YiiRouteUrlResolver implements RouteUrlResolverInterface
{
    /** `locales: 'all'` met an empty `router.locales`: warned about once, not once per record. */
    private bool $warnedAboutLocales = false;

    /**
     * @param list<string>         $locales the locales of `locales: 'all'` (`router.locales`)
     * @param LoggerInterface|null $logger  where `locales: 'all'` over an empty list is warned about (once per process)
     */
    public function __construct(
        private readonly Config $config,
        private readonly array $locales = [],
        private readonly string $localeParameter = 'language',
        private readonly bool $setAppLocale = true,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function locales(array|string $locales): array
    {
        return RouteOrigin::expand($locales, $this->locales, $this->logger, 'router.locales', $this->warnedAboutLocales);
    }

    public function generate(string $route, array $params, ?string $locale = null, ?string $host = null): string
    {
        foreach ($params as $name => $value) {
            if ($value instanceof BaseActiveRecord) {
                $params[$name] = self::primaryKeyOf($value, $route, (string) $name);
            }
        }
        if ($locale !== null) {
            $params[$this->localeParameter] = $locale;
        }
        $manager = $this->urlManager();
        $previousLanguage = null;
        $app = App::current();
        if ($locale !== null && $this->setAppLocale && $app->language !== $locale) {
            $previousLanguage = $app->language;
            $app->language = $locale;
        }
        try {
            $url = $manager->createAbsoluteUrl(['/' . ltrim($route, '/')] + $params);
        } catch (Throwable $e) {
            throw RouteOrigin::generationFailed($route, $e);
        } finally {
            if ($previousLanguage !== null) {
                $app->language = $previousLanguage;
            }
        }

        return $host === null ? $url : RouteOrigin::rebase($url, RouteOrigin::pinnedRoot($this->config, $host));
    }

    /**
     * The application's UrlManager, or a clone that knows the base_url origin when the application has no request
     * to take it from (console, queue worker).
     */
    private function urlManager(): UrlManager
    {
        $app = App::current();
        $manager = $app->getUrlManager();
        if ($app instanceof \yii\web\Application) {
            return $manager;
        }
        $base = $this->config->baseUrl;
        if ($base === null) {
            throw RouteOrigin::noRequestHost('a console application');
        }
        $clone = clone $manager;
        $parts = parse_url($base);
        $origin = \is_array($parts) && isset($parts['scheme'], $parts['host']) ? $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '') : rtrim($base, '/');
        $path = \is_array($parts) && isset($parts['path']) ? rtrim($parts['path'], '/') : '';
        try {
            $manager->getHostInfo();
        } catch (Throwable) {
            $clone->setHostInfo($origin);
        }
        try {
            $manager->getBaseUrl();
        } catch (Throwable) {
            $clone->setBaseUrl($path);
        }
        try {
            $manager->getScriptUrl();
        } catch (Throwable) {
            $clone->setScriptUrl($path . ($manager->showScriptName || !$manager->enablePrettyUrl ? '/index.php' : ''));
        }

        return $clone;
    }

    private static function primaryKeyOf(BaseActiveRecord $record, string $route, string $param): mixed
    {
        $pk = $record->getPrimaryKey(true);
        $pk = \is_array($pk) ? array_values($pk) : [];
        if (\count($pk) !== 1) {
            throw new ConfigurationException(\sprintf('Route "%s": parameter "%s" is "self" but %s has %d primary key columns; name the columns explicitly in params.', $route, $param, $record::class, \count($pk)));
        }

        return $pk[0];
    }
}
