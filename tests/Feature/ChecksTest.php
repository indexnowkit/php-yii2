<?php

declare(strict_types=1);

namespace IndexNowKit\Yii2\Tests\Feature;

use IndexNowKit\Check\CheckLevel;
use IndexNowKit\Check\CheckReport;
use IndexNowKit\Check\DebounceStoreCheck;
use IndexNowKit\Config;
use IndexNowKit\Yii2\Check\CacheProbe;
use IndexNowKit\Yii2\Check\QueueCheck;
use IndexNowKit\Yii2\Check\UrlManagerCheck;
use IndexNowKit\Yii2\IndexNowComponent;
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

    #[TestDox('debounce: off is ok, memory is a warning, an existing cache component is ok (the default when unset), a missing one an error (core DebounceStoreCheck + the Yii cache probe)')]
    public function testDebounceStoreCheck(): void
    {
        $check = static fn(array $debounce): DebounceStoreCheck => new DebounceStoreCheck(Config::fromArray(['key' => Fixtures::KEY, 'debounce' => $debounce]), (new CacheProbe())(...), IndexNowComponent::DEFAULT_DEBOUNCE_STORE);

        self::assertSame([CheckLevel::Ok], $this->levels($check(['per_url' => 0])));
        self::assertSame([CheckLevel::Warning], $this->levels($check(['per_url' => 600, 'store' => 'memory'])));
        self::assertSame([CheckLevel::Ok], $this->levels($check(['per_url' => 600, 'store' => 'cache'])));
        self::assertSame([CheckLevel::Ok], $this->levels($check(['per_url' => 600])), 'unset = the cache component');
        self::assertStringContainsString('cache component "cache"', $this->messages($check(['per_url' => 600]))[0]);
        $failing = $check(['per_url' => 600, 'store' => 'missing']);
        self::assertSame([CheckLevel::Error], $this->levels($failing));
        self::assertStringContainsString('component "missing" does not exist', $this->messages($failing)[0]);
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
