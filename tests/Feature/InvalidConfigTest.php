<?php

declare(strict_types=1);

namespace IndexNowKit\Yii2\Tests\Feature;

use IndexNowKit\Yii2\Tests\Fixtures\Post;
use IndexNowKit\Yii2\Tests\Yii2TestCase;
use PHPUnit\Framework\Attributes\TestDox;

final class InvalidConfigTest extends Yii2TestCase
{
    protected function optionOverrides(): array
    {
        return ['key' => 'short', 'environment' => 'prod'];
    }

    #[TestDox('an invalid key does not throw from a save: IndexNow runs disabled, one critical line')]
    public function testDisabledOnInvalidConfig(): void
    {
        (new Post(['slug' => 'x']))->save(false);
        $this->kit()->flush();

        self::assertSame([], $this->transport->posts);
        self::assertFalse($this->component()->config()->enabled);
        self::assertCount(1, $this->logger->messages('critical'));
        self::assertStringContainsString('IndexNow is disabled until it is fixed', $this->logger->messages('critical')[0]);
    }
}
