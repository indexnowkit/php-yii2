<?php

declare(strict_types=1);

/*
 * Test bootstrap of the split repository: composer autoload plus Yii.php, which defines the Yii class (not
 * autoloadable) and the YII_* constants. The monorepo uses php/tests/bootstrap.php.
 */
require __DIR__ . '/../vendor/autoload.php';

defined('YII_ENV') or define('YII_ENV', 'test');
defined('YII_DEBUG') or define('YII_DEBUG', true);
require __DIR__ . '/../vendor/yiisoft/yii2/Yii.php';
