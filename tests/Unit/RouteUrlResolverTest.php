<?php

declare(strict_types=1);

namespace IndexNowKit\Yii2\Tests\Unit;

use IndexNowKit\Config;
use IndexNowKit\Exception\ConfigurationException;
use IndexNowKit\Yii2\Tests\Fixtures\Item;
use IndexNowKit\Yii2\Tests\Yii2TestCase;
use IndexNowKit\Yii2\Url\YiiRouteUrlResolver;
use PHPUnit\Framework\Attributes\TestDox;

final class RouteUrlResolverTest extends Yii2TestCase
{
    protected function console(): bool
    {
        return true;
    }

    #[TestDox('in a console application URLs are generated on base_url; a pinned host is rebased; a self parameter is the primary key')]
    public function testConsoleGeneration(): void
    {
        $resolver = $this->component()->routeResolver();

        self::assertSame('https://www.example.com/posts/hello', $resolver->generate('post/view', ['slug' => 'hello']));
        self::assertSame('https://example.de/posts/hello', $resolver->generate('post/view', ['slug' => 'hello'], null, 'example.de'));
        self::assertSame('https://www.example.com/de/articles/hallo', $resolver->generate('article/view', ['slug' => 'hallo'], 'de'));
        self::assertSame('en', $this->app->language, 'the application language is restored');

        $item = new Item(['name' => 'x']);
        $item->save(false);
        self::assertSame('https://www.example.com/items/' . $item->id, $resolver->generate('item/view', ['id' => $item]));

        self::assertSame(['en', 'de'], $resolver->locales('all'));
        self::assertSame([null], $resolver->locales('current'));
        self::assertSame(['fr'], $resolver->locales(['fr']));
    }

    #[TestDox('an unknown route falls back to the query form (Yii has no route registry) and a missing base_url is a configuration error')]
    public function testFallbacks(): void
    {
        $resolver = $this->component()->routeResolver();
        self::assertSame('https://www.example.com/nope/view?x=1', $resolver->generate('nope/view', ['x' => 1]));

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageMatches('/set base_url/');
        (new YiiRouteUrlResolver(Config::fromArray(['key' => self::KEY])))->generate('post/view', ['slug' => 'a']);
    }
}
