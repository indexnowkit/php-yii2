<?php

declare(strict_types=1);

namespace IndexNowKit\Yii2\Tests\Feature;

use IndexNowKit\Attribute\IndexNow;
use IndexNowKit\Event;
use IndexNowKit\Exception\ConfigurationException;
use IndexNowKit\Url\RouteUrlResolverInterface;
use IndexNowKit\Url\UrlResolverInterface;
use IndexNowKit\Yii2\IndexNowComponent;
use IndexNowKit\Yii2\Tests\Fixtures\Post;
use IndexNowKit\Yii2\Tests\Yii2TestCase;
use PHPUnit\Framework\Attributes\TestDox;
use RuntimeException;
use yii\base\Component;
use yii\BaseYii;

/**
 * A resolver of `#[IndexNow(resolver: ...)]` that is an application component and takes the router bridge from the
 * component: what an application writes when a URL needs more than a route and its parameters.
 */
final class UppercaseResolver extends Component implements UrlResolverInterface
{
    public function __construct(private readonly RouteUrlResolverInterface $router, array $config = [])
    {
        parent::__construct($config);
    }

    /** @return list<string> */
    public function resolve(object $subject, Event $event): array
    {
        \assert($subject instanceof Post);

        return [$this->router->generate('post/view', ['slug' => strtoupper($subject->slug)])];
    }
}

/**
 * `#[IndexNow(resolver: 'id')]` in Yii2: an application component, a container definition or an instantiable class,
 * looked up through `Wiring::locateResolver()` and wrapped by the core's `Url\ArrayResolverLocator`.
 */
final class ResolverLocatorTest extends Yii2TestCase
{
    protected function appOverrides(): array
    {
        return ['components' => ['uppercase' => static fn(): UppercaseResolver => new UppercaseResolver(self::router())]];
    }

    #[TestDox('a resolver named by component id is used for the record')]
    public function testComponentIdResolver(): void
    {
        $this->component()->observe(Post::class, [new IndexNow(resolver: 'uppercase')]);
        $post = new Post(['slug' => 'loud']);
        $post->save(false);
        $this->kit()->flush();

        self::assertSame(['https://www.example.com/posts/LOUD'], $this->sentUrls());
    }

    #[TestDox('an unknown resolver id is the core ConfigurationException, logged by the hook; the record still saves')]
    public function testUnknownResolverIdDoesNotBreakTheSave(): void
    {
        $this->component()->observe(Post::class, [new IndexNow(resolver: 'nope-resolver')]);
        $post = new Post(['slug' => 'quiet']);
        $post->save(false);
        $this->kit()->flush();

        self::assertSame([], $this->sentUrls());
        self::assertNotNull(Post::findOne(['slug' => 'quiet']), 'the save went through');
        $errors = implode("\n", $this->logger->messages('error'));
        self::assertStringContainsString('nope-resolver', $errors);
    }

    #[TestDox('the locator names the adapter ids in the core text: an unknown id, and a container definition that throws')]
    public function testLocatorTexts(): void
    {
        $locator = $this->component()->services()->requireResolverLocator();

        try {
            $locator->get('nope-resolver');
            self::fail('expected a ConfigurationException');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString('is neither a component, a container definition nor an instantiable class', $e->getMessage());
        }

        BaseYii::$container->set('throwing-resolver', static fn(): object => throw new RuntimeException('boom'));
        try {
            $locator->get('throwing-resolver');
            self::fail('expected a ConfigurationException');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString('cannot be built by the container: boom', $e->getMessage(), 'the wrapping is the core locator\'s, not the adapter\'s own');
        }
    }

    private static function router(): RouteUrlResolverInterface
    {
        $component = BaseYii::$app?->get('indexnow');
        \assert($component instanceof IndexNowComponent);

        return $component->routeResolver();
    }
}
