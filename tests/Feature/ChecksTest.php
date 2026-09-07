<?php

declare(strict_types=1);

namespace IndexNowKit\Yii2\Tests\Feature;

use IndexNowKit\Attribute\IndexNow;
use IndexNowKit\Check\CheckInterface;
use IndexNowKit\Check\CheckLevel;
use IndexNowKit\Check\CheckReport;
use IndexNowKit\Check\DebounceStoreCheck;
use IndexNowKit\Config;
use IndexNowKit\Exception\ConfigurationException;
use IndexNowKit\Testing\Conformance\CheckOutputAssertions;
use IndexNowKit\Yii2\Check\CacheProbe;
use IndexNowKit\Yii2\Check\QueueCheck;
use IndexNowKit\Yii2\Check\RouterCheck;
use IndexNowKit\Yii2\Check\UrlManagerCheck;
use IndexNowKit\Yii2\IndexNowComponent;
use IndexNowKit\Yii2\Tests\Fixtures\MultiPost;
use IndexNowKit\Yii2\Tests\Support\Fixtures;
use IndexNowKit\Yii2\Tests\Yii2TestCase;
use IndexNowKit\Yii2\Wiring;
use PHPUnit\Framework\Attributes\TestDox;
use stdClass;
use yii\BaseYii;

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

    #[TestDox('every line of the whole check, adapter checks included, carries a code (the API of check --json)')]
    public function testEveryCheckLineHasACode(): void
    {
        $report = $this->component()->checker()->run();

        CheckOutputAssertions::assertEveryItemHasCode($report, 'queue.dispatch', DebounceStoreCheck::CODE, 'url_manager.rule', 'active_record.enabled', 'sitemap.spool', 'key_file.status');
    }

    #[TestDox('key file: the URL rule is reported in the web application; disabled serving is ok')]
    public function testUrlManagerCheck(): void
    {
        $check = new UrlManagerCheck(Fixtures::options());
        self::assertSame([CheckLevel::Ok], $this->levels($check));
        self::assertStringContainsString('/<key>.txt', $this->messages($check)[0]);
        self::assertSame([CheckLevel::Ok], $this->levels(new UrlManagerCheck(['key_file' => ['enabled' => false]])));
    }

    #[TestDox('router: the configured locales are an ok line; a rule with locales: all and an empty router.locales is a warning naming the option')]
    public function testRouterCheck(): void
    {
        $rules = $this->component()->rules();
        $configured = new RouterCheck(['en', 'de'], [MultiPost::class], $rules);
        self::assertSame([CheckLevel::Ok], $this->levels($configured));
        self::assertStringContainsString("locales: 'all' expands to en, de", $this->messages($configured)[0]);

        $rules->register(MultiPost::class, [new IndexNow(route: 'article/view', params: ['slug' => 'slug'], locales: 'all')]);
        $missing = new RouterCheck([], [MultiPost::class], $rules);
        self::assertSame([CheckLevel::Warning], $this->levels($missing));
        self::assertStringContainsString('router.locales is empty', $this->messages($missing)[0]);

        $none = new RouterCheck([], [], $rules);
        self::assertSame([CheckLevel::Ok], $this->levels($none), 'no rule asks for all the locales: nothing to warn about');
    }

    #[TestDox('checks: an own CheckInterface named by component id appears in the report; anything else is a ConfigurationException naming it')]
    public function testChecksOption(): void
    {
        BaseYii::$container->set('extra-check', static fn(): CheckInterface => new class implements CheckInterface {
            public function check(CheckReport $report): void
            {
                $report->ok('extra: the application check ran', 'extra.check');
            }
        });
        $component = $this->component();
        $component->checks = ['extra-check'];

        $codes = array_map(static fn($item): ?string => $item->code, $component->checker()->run()->items());
        self::assertContains('extra.check', $codes);

        /** @var list<mixed> $badChecks */
        $badChecks = [new stdClass()];
        $component->checks = $badChecks;
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('is not a ' . CheckInterface::class);
        (new Wiring($component))->checks($component->services());
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
