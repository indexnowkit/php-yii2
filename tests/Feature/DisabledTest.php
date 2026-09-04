<?php

declare(strict_types=1);

namespace IndexNowKit\Yii2\Tests\Feature;

use IndexNowKit\Yii2\Tests\Fixtures\Post;
use IndexNowKit\Yii2\Tests\Yii2TestCase;
use PHPUnit\Framework\Attributes\TestDox;

final class DisabledTest extends Yii2TestCase
{
    protected function optionOverrides(): array
    {
        return ['active_record' => ['enabled' => false]];
    }

    #[TestDox('active_record.enabled false: the behavior and the models list are inert, submit() still works')]
    public function testInert(): void
    {
        (new Post(['slug' => 'silent']))->save(false);
        $this->kit()->flush();
        self::assertSame([], $this->transport->posts);

        $this->component()->submit(['/manual']);
        self::assertSame(['https://www.example.com/manual'], $this->sentUrls());
    }
}
