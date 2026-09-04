<?php

declare(strict_types=1);

namespace IndexNowKit\Yii2\Tests\Support;

use IndexNowKit\Testing\ArrayLogger;
use IndexNowKit\Testing\FakeTransport;
use IndexNowKit\Yii2\IndexNowComponent;
use IndexNowKit\Yii2\Tests\Fixtures\ModelPost;
use Yii;
use yii\console\Application as ConsoleApplication;
use yii\db\Connection;
use yii\web\Application as WebApplication;

/**
 * The package's test application: component options, URL rules and schema of the conformance fixtures, and the
 * factories for a web or console application in memory (sqlite, FakeTransport instead of HTTP, ArrayLogger).
 */
final class Fixtures
{
    public const KEY = 'abcdef1234567890abcdef1234567890';
    public const SECOND_KEY = 'fedcba0987654321fedcba0987654321';
    public const BASE_URL = 'https://www.example.com';

    private function __construct() {}

    /**
     * @return array<string, mixed>
     */
    public static function options(): array
    {
        return [
            'key' => self::KEY,
            'base_url' => self::BASE_URL,
            'hosts' => ['example.de' => self::SECOND_KEY],
            'dispatch' => 'sync',
            'debounce' => ['per_url' => 0, 'store' => 'memory'],
            'router' => ['languages' => ['en', 'de']],
            'collector' => ['detect_leaks' => false],
            'sitemap' => ['spool' => 'memory'],
            'active_record' => ['models' => [ModelPost::class]],
        ];
    }

    /**
     * Overrides on top of the test options: nested arrays merge, an empty array (or a list) replaces.
     *
     * @param array<string, mixed> $base
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    public static function merge(array $base, array $overrides): array
    {
        foreach ($overrides as $key => $value) {
            $current = $base[$key] ?? null;
            $base[$key] = \is_array($value) && $value !== [] && !array_is_list($value) && \is_array($current) ? self::merge($current, $value) : $value;
        }

        return $base;
    }

    /**
     * @return array<string, mixed>
     */
    public static function urlRules(): array
    {
        return [
            'posts/<slug:[\w-]+>' => 'post/view',
            'pages/<slug:[\w-]+>' => 'page/view',
            'amp/<slug:[\w-]+>' => 'post/amp',
            'categories/<slug:[\w-]+>' => 'category/view',
            '<language:(en|de)>/articles/<slug:[\w-]+>' => 'article/view',
            'items/<id:\d+>' => 'item/view',
        ];
    }

    /**
     * @param array<string, mixed> $optionOverrides
     * @param array<string, mixed> $componentOverrides other properties of the indexnow component (transport, logger, ...)
     * @param array<string, mixed> $appOverrides       merged into the application config
     *
     * @return array<string, mixed>
     */
    public static function appConfig(FakeTransport $transport, ArrayLogger $logger, array $optionOverrides = [], array $componentOverrides = [], array $appOverrides = []): array
    {
        $config = [
            'id' => 'indexnowkit-test',
            'basePath' => \dirname(__DIR__),
            'language' => 'en',
            'bootstrap' => ['indexnow'],
            'components' => [
                'db' => ['class' => Connection::class, 'dsn' => 'sqlite::memory:'],
                'cache' => ['class' => \yii\caching\ArrayCache::class],
                'urlManager' => ['enablePrettyUrl' => true, 'showScriptName' => false, 'rules' => self::urlRules()],
                'indexnow' => ['class' => IndexNowComponent::class, 'options' => self::merge(self::options(), $optionOverrides), 'transport' => $transport, 'logger' => $logger] + $componentOverrides,
            ],
        ];

        return self::merge($config, $appOverrides);
    }

    /**
     * @param array<string, mixed> $optionOverrides
     * @param array<string, mixed> $componentOverrides
     * @param array<string, mixed> $appOverrides
     */
    public static function webApp(FakeTransport $transport, ArrayLogger $logger, array $optionOverrides = [], array $componentOverrides = [], array $appOverrides = []): WebApplication
    {
        $config = self::appConfig($transport, $logger, $optionOverrides, $componentOverrides, $appOverrides);
        $config['components']['request'] = ['cookieValidationKey' => 'test', 'scriptFile' => __DIR__ . '/index.php', 'scriptUrl' => '/index.php', 'baseUrl' => '', 'hostInfo' => self::BASE_URL];
        $app = new WebApplication($config);
        self::migrate($app->getDb());

        return $app;
    }

    /**
     * @param array<string, mixed> $optionOverrides
     * @param array<string, mixed> $componentOverrides
     * @param array<string, mixed> $appOverrides
     */
    public static function consoleApp(FakeTransport $transport, ArrayLogger $logger, array $optionOverrides = [], array $componentOverrides = [], array $appOverrides = []): ConsoleApplication
    {
        $config = self::appConfig($transport, $logger, $optionOverrides, $componentOverrides, $appOverrides);
        $app = new ConsoleApplication($config);
        self::migrate($app->getDb());

        return $app;
    }

    /** Undo what an application did to the process. */
    public static function destroy(): void
    {
        \yii\base\Event::offAll();
        Yii::$app?->getErrorHandler()->unregister();
        Yii::$app = null;
        Yii::$container = new \yii\di\Container();
    }

    public static function migrate(Connection $db): void
    {
        $db->createCommand()->createTable('posts', ['id' => 'pk', 'slug' => 'string NOT NULL', 'title' => 'string NOT NULL DEFAULT \'title\'', 'published' => 'boolean NOT NULL DEFAULT 1', 'views' => 'integer NOT NULL DEFAULT 0'])->execute();
        $db->createCommand()->createTable('multi_posts', ['id' => 'pk', 'slug' => 'string NOT NULL', 'published' => 'boolean NOT NULL DEFAULT 1', 'amp' => 'boolean NOT NULL DEFAULT 0'])->execute();
        $db->createCommand()->createTable('categories', ['id' => 'pk', 'slug' => 'string NOT NULL'])->execute();
        $db->createCommand()->createTable('categorized_posts', ['id' => 'pk', 'slug' => 'string NOT NULL', 'views' => 'integer NOT NULL DEFAULT 0', 'category_id' => 'integer', 'updated_at' => 'integer'])->execute();
        $db->createCommand()->createTable('tags', ['id' => 'pk', 'name' => 'string NOT NULL'])->execute();
        $db->createCommand()->createTable('categorized_post_tags', ['post_id' => 'integer NOT NULL', 'tag_id' => 'integer NOT NULL'])->execute();
        foreach (['untracked', 'broken', 'bad_attribute', 'model_posts'] as $name) {
            $db->createCommand()->createTable($name, ['id' => 'pk', 'name' => 'string NOT NULL'])->execute();
        }
        $db->createCommand()->createTable('items', ['id' => 'pk', 'name' => 'string NOT NULL'])->execute();
    }
}
