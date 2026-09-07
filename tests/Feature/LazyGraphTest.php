<?php

declare(strict_types=1);

namespace IndexNowKit\Yii2\Tests\Feature;

use IndexNowKit\Yii2\Tests\Fixtures\Post;
use IndexNowKit\Yii2\Tests\Yii2TestCase;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * The ActiveRecord hook resolves URLs over `Adapter\Services::changes()`: a `save()` builds the rules, the resolver
 * and the extractor, and nothing that talks to the network. The client, the transport and the debounce store are
 * built by the flush, so an unreachable HTTP client is a failure of the flush, never of the save.
 */
final class LazyGraphTest extends Yii2TestCase
{
    protected function optionOverrides(): array
    {
        return ['http' => ['client' => 'no-such-http-client']];
    }

    protected function appOverrides(): array
    {
        // no transport instance: the graph has to resolve `http.client` to build one
        return ['components' => ['indexnow' => ['transport' => null]]];
    }

    #[TestDox('a save() with an http.client the container does not know does not throw: the client is built by the flush, not by the hook')]
    public function testSaveDoesNotBuildTheClient(): void
    {
        (new Post(['slug' => 'lazy']))->save(false);

        self::assertSame([], $this->logger->messages('error'), 'nothing failed while the hook resolved the URL');
        self::assertTrue($this->component()->services()->hasCollected(), 'the URL reached the collector');

        $this->component()->flushIfCollected();

        self::assertCount(1, $this->logger->messages('error'), 'the client is built here, and here it fails');
        self::assertStringContainsString('no-such-http-client', json_encode(array_map(static fn(array $record): mixed => $record['context'] ?? [], $this->logger->records), JSON_THROW_ON_ERROR | JSON_PARTIAL_OUTPUT_ON_ERROR));
    }
}
