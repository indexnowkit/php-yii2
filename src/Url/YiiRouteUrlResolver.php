<?php

declare(strict_types=1);

namespace IndexNowKit\Yii2\Url;

use IndexNowKit\Config;
use IndexNowKit\Exception\ConfigurationException;
use IndexNowKit\Url\RouteUrlResolverInterface;
use IndexNowKit\Yii2\App;
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
 * - `$locale` is passed as the `router.language_parameter` GET parameter, and `Yii::$app->language` is switched for
 *   the duration when `router.set_app_language` is on.
 */
final class YiiRouteUrlResolver implements RouteUrlResolverInterface
{
    /**
     * @param list<string> $languages languages of `locales: 'all'` (`router.languages`)
     */
    public function __construct(
        private readonly Config $config,
        private readonly array $languages = [],
        private readonly string $languageParameter = 'language',
        private readonly bool $setAppLanguage = true,
    ) {}

    public function locales(array|string $locales): array
    {
        if (\is_array($locales)) {
            return $locales === [] ? [null] : $locales;
        }
        if ($locales === 'all' && $this->languages !== []) {
            return $this->languages;
        }

        return [null];
    }

    public function generate(string $route, array $params, ?string $locale = null, ?string $host = null): string
    {
        foreach ($params as $name => $value) {
            if ($value instanceof BaseActiveRecord) {
                $params[$name] = self::primaryKeyOf($value, $route, (string) $name);
            }
        }
        if ($locale !== null) {
            $params[$this->languageParameter] = $locale;
        }
        $manager = $this->urlManager();
        $previousLanguage = null;
        $app = App::current();
        if ($locale !== null && $this->setAppLanguage && $app->language !== $locale) {
            $previousLanguage = $app->language;
            $app->language = $locale;
        }
        try {
            $url = $manager->createAbsoluteUrl(['/' . ltrim($route, '/')] + $params);
        } catch (Throwable $e) {
            throw new ConfigurationException(\sprintf('Cannot generate route "%s": %s', $route, $e->getMessage()), 0, $e);
        } finally {
            if ($previousLanguage !== null) {
                $app->language = $previousLanguage;
            }
        }
        $root = $host !== null ? ($this->config->baseUrlFor($host) ?? 'https://' . $host) : null;

        return $root === null ? $url : self::rebase($url, $root);
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
            throw new ConfigurationException('No request to take the host from: set base_url to generate URLs in a console application.');
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

    /**
     * Replaces scheme, host and port of $url with those of $root; path, query and fragment stay.
     */
    private static function rebase(string $url, string $root): string
    {
        $target = parse_url($root);
        $source = parse_url($url);
        if (!\is_array($target) || !\is_array($source) || !isset($target['scheme'], $target['host'])) {
            return $url;
        }
        $origin = $target['scheme'] . '://' . $target['host'] . (isset($target['port']) ? ':' . $target['port'] : '');
        $rest = ($source['path'] ?? '/') . (isset($source['query']) ? '?' . $source['query'] : '') . (isset($source['fragment']) ? '#' . $source['fragment'] : '');

        return $origin . $rest;
    }
}
