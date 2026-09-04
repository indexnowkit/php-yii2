<?php

declare(strict_types=1);

namespace IndexNowKit\Yii2\Tests\Unit;

use IndexNowKit\Yii2\Log\YiiLogger;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use yii\log\Logger;

final class YiiLoggerTest extends TestCase
{
    #[TestDox('PSR-3 levels map to Yii levels, placeholders are interpolated, the exception is appended, the category is fixed')]
    public function testMapping(): void
    {
        $yii = new Logger();
        $yii->flushInterval = 1000;
        $logger = new YiiLogger($yii, 'seo');

        $logger->error('failed for {url} ({count})', ['url' => 'https://a/', 'count' => 2, 'exception' => new RuntimeException('boom'), 'list' => ['x']]);
        $logger->debug('trace {flag}', ['flag' => true]);
        $logger->warning('warn');
        $logger->info('info');

        self::assertCount(4, $yii->messages);
        [$text, $level, $category] = $yii->messages[0];
        self::assertSame('failed for https://a/ (2) [RuntimeException: boom]', $text);
        self::assertSame(Logger::LEVEL_ERROR, $level);
        self::assertSame('seo', $category);
        self::assertSame(['trace true', Logger::LEVEL_TRACE], [$yii->messages[1][0], $yii->messages[1][1]]);
        self::assertSame(Logger::LEVEL_WARNING, $yii->messages[2][1]);
        self::assertSame(Logger::LEVEL_INFO, $yii->messages[3][1]);
    }
}
