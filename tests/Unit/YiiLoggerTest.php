<?php

declare(strict_types=1);

namespace IndexNowKit\Yii2\Tests\Unit;

use IndexNowKit\Yii2\Log\YiiLogger;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Psr\Log\InvalidArgumentException;
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

    #[TestDox('a level PSR-3 does not define is a Psr\\Log\\InvalidArgumentException, as the standard requires')]
    public function testUnknownLevel(): void
    {
        $yii = new Logger();
        $logger = new YiiLogger($yii);

        try {
            $logger->log('verbose', 'x');
            self::fail('unknown level');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('Unknown log level "verbose"', $e->getMessage());
        }
        try {
            $logger->log(7, 'x');
            self::fail('a non-string level');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('Unknown log level "7"', $e->getMessage());
        }
        self::assertSame([], $yii->messages, 'nothing was logged at info instead');
    }
}
