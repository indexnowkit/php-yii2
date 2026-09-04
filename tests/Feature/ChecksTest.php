<?php

declare(strict_types=1);

namespace IndexNowKit\Yii2\Tests\Feature;

use IndexNowKit\Check\CheckLevel;
use IndexNowKit\Check\CheckReport;
use IndexNowKit\Yii2\Check\CacheCheck;
use IndexNowKit\Yii2\Check\QueueCheck;
use IndexNowKit\Yii2\Check\UrlManagerCheck;
use IndexNowKit\Yii2\Tests\Support\Fixtures;
use IndexNowKit\Yii2\Tests\Yii2TestCase;
use PHPUnit\Framework\Attributes\TestDox;

final class ChecksTest extends Yii2TestCase
{
    #[TestDox('queue: sync dispatch is an ok line; queue dispatch without the component is an error')]
    public function testQueueCheck(): void
    {
        self::assertSame([CheckLevel::Ok], $this->levels(new QueueCheck(Fixtures::options(), 'sync', false)));
        $check = new QueueCheck(Fixtures::options(), 'queue', false);
        self::assertSame([CheckLevel::Error], $this->levels($check));
        self::assertStringContainsString('yiisoft/yii2-queue', $this->messages($check)[0]);
    }

    #[TestDox('debounce: off is ok, memory is a warning, an existing cache component is ok, a missing one an error')]
    public function testCacheCheck(): void
    {
        self::assertSame([CheckLevel::Ok], $this->levels(new CacheCheck(['debounce' => ['per_url' => 0]])));
        self::assertSame([CheckLevel::Warning], $this->levels(new CacheCheck(['debounce' => ['per_url' => 600, 'store' => 'memory']])));
        self::assertSame([CheckLevel::Ok], $this->levels(new CacheCheck(['debounce' => ['per_url' => 600, 'store' => 'cache']])));
        $check = new CacheCheck(['debounce' => ['per_url' => 600, 'store' => 'missing']]);
        self::assertSame([CheckLevel::Error], $this->levels($check));
    }

    #[TestDox('key file: the URL rule is reported in the web application; disabled serving is ok')]
    public function testUrlManagerCheck(): void
    {
        $check = new UrlManagerCheck(Fixtures::options());
        self::assertSame([CheckLevel::Ok], $this->levels($check));
        self::assertStringContainsString('/<key>.txt', $this->messages($check)[0]);
        self::assertSame([CheckLevel::Ok], $this->levels(new UrlManagerCheck(['key_file' => ['enabled' => false]])));
    }

    /**
     * @return list<CheckLevel>
     */
    private function levels(\IndexNowKit\Check\CheckInterface $check): array
    {
        $report = new CheckReport();
        $check->check($report);

        return array_map(static fn($item): CheckLevel => $item->level, $report->items());
    }

    /**
     * @return list<string>
     */
    private function messages(\IndexNowKit\Check\CheckInterface $check): array
    {
        $report = new CheckReport();
        $check->check($report);

        return array_map(static fn($item): string => $item->message, $report->items());
    }
}
