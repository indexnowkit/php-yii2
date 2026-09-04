<?php

declare(strict_types=1);

namespace IndexNowKit\Yii2\Tests\Feature;

use IndexNowKit\Yii2\Tests\Yii2TestCase;
use PHPUnit\Framework\Attributes\TestDox;
use yii\web\NotFoundHttpException;

final class KeyFileDisabledTest extends Yii2TestCase
{
    protected function optionOverrides(): array
    {
        return ['key_file' => ['enabled' => false]];
    }

    #[TestDox('H03 key_file.enabled false -> no URL rule, /{key}.txt is a 404')]
    public function testNoRoute(): void
    {
        \assert($this->app instanceof \yii\web\Application);
        $request = $this->app->getRequest();
        $request->setPathInfo(self::KEY . '.txt');
        $this->expectException(NotFoundHttpException::class);
        $this->app->handleRequest($request);
    }
}
