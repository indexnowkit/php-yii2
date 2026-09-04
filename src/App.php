<?php

declare(strict_types=1);

namespace IndexNowKit\Yii2;

use Yii;
use yii\base\Application;
use yii\base\InvalidConfigException;

/**
 * `Yii::$app` with the null case handled once: the package is only ever used from inside a running application.
 *
 * @internal
 */
final class App
{
    private function __construct() {}

    public static function current(): Application
    {
        $app = Yii::$app;
        if ($app === null) {
            throw new InvalidConfigException('indexnowkit/yii2 needs a running Yii application (Yii::$app is null).');
        }

        return $app;
    }

    public static function web(): ?\yii\web\Application
    {
        $app = Yii::$app;

        return $app instanceof \yii\web\Application ? $app : null;
    }

    public static function isConsole(): bool
    {
        return Yii::$app instanceof \yii\console\Application;
    }

    /**
     * A component by id, or null when the application has none under that id.
     */
    public static function component(string $id): ?object
    {
        $app = self::current();
        if (!$app->has($id)) {
            return null;
        }
        $component = $app->get($id);

        return \is_object($component) ? $component : null;
    }

    public static function indexNow(string $id = 'indexnow'): ?IndexNowComponent
    {
        $component = self::component($id);

        return $component instanceof IndexNowComponent ? $component : null;
    }
}
