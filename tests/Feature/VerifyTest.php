<?php

declare(strict_types=1);

namespace IndexNowKit\Yii2\Tests\Feature;

use IndexNowKit\Http\Response;
use IndexNowKit\Verify\VerifyingSubmitter;
use IndexNowKit\Verify\VerifyingSubmitterFactory;
use IndexNowKit\Yii2\Event\ResultEvent;
use IndexNowKit\Yii2\IndexNowComponent;
use IndexNowKit\Yii2\Tests\Fixtures\Post;
use IndexNowKit\Yii2\Tests\Yii2TestCase;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * indexnowkit/verify installed and `verify.enabled: true` with `dispatch: sync` in a web application: the graph's
 * submitter is the decorator, a noindex post is skipped at flush, EVENT_RESULT carries the skipped result once.
 */
final class VerifyTest extends Yii2TestCase
{
    protected function optionOverrides(): array
    {
        return ['verify' => ['enabled' => true, 'redirect' => 'follow']];
    }

    #[TestDox('a noindex post is skipped at flush; EVENT_RESULT sees ok and skipped once each; the command factory is decorated, the plain one stays apart')]
    public function testNoindexPostIsSkipped(): void
    {
        $seen = [];
        $this->component()->on(IndexNowComponent::EVENT_RESULT, static function (ResultEvent $event) use (&$seen): void {
            $seen[] = $event->result->status->value . ':' . ($event->result->reason?->value ?? '-');
        });
        $this->transport
            ->onGet('https://www.example.com/posts/fine', new Response(200))
            ->onGet('https://www.example.com/posts/hidden', new Response(200, '<head><meta name="robots" content="noindex"></head>'));

        (new Post(['slug' => 'fine']))->save(false);
        (new Post(['slug' => 'hidden']))->save(false);
        $this->kit()->flush();

        self::assertSame(['https://www.example.com/posts/fine'], $this->sentUrls());
        self::assertContains('https://www.example.com/robots.txt', $this->transport->gets);
        sort($seen);
        self::assertSame(['ok:-', 'skipped:noindex'], $seen);
        self::assertInstanceOf(VerifyingSubmitter::class, $this->component()->submitter());
        self::assertInstanceOf(VerifyingSubmitterFactory::class, $this->component()->submitterFactory());
        self::assertNotInstanceOf(VerifyingSubmitterFactory::class, $this->component()->unverifiedSubmitterFactory());
        self::assertTrue($this->component()->verifyEnabled());
        self::assertContains('indexnow verify: skipped https://www.example.com/posts/hidden: noindex (meta robots)', $this->logger->messages('info'));
    }
}
