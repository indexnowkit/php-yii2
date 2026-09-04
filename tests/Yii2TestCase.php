<?php

declare(strict_types=1);

namespace IndexNowKit\Yii2\Tests;

use IndexNowKit\IndexNowKit;
use IndexNowKit\Testing\ArrayLogger;
use IndexNowKit\Testing\FakeTransport;
use IndexNowKit\Yii2\IndexNowComponent;
use IndexNowKit\Yii2\Tests\Support\Fixtures;
use PHPUnit\Framework\TestCase;
use yii\base\Application;

/**
 * A Yii application in memory (web by default), the package bootstrapped, sqlite with the fixture schema, a
 * FakeTransport instead of HTTP and an ArrayLogger instead of Yii's log.
 */
abstract class Yii2TestCase extends TestCase
{
    public const KEY = Fixtures::KEY;
    public const SECOND_KEY = Fixtures::SECOND_KEY;
    public const BASE_URL = Fixtures::BASE_URL;

    protected FakeTransport $transport;
    protected ArrayLogger $logger;
    protected Application $app;

    protected function setUp(): void
    {
        $this->transport = new FakeTransport();
        $this->logger = new ArrayLogger();
        $this->app = $this->console()
            ? Fixtures::consoleApp($this->transport, $this->logger, $this->optionOverrides(), $this->componentOverrides(), $this->appOverrides())
            : Fixtures::webApp($this->transport, $this->logger, $this->optionOverrides(), $this->componentOverrides(), $this->appOverrides());
    }

    protected function tearDown(): void
    {
        Fixtures::destroy();
    }

    protected function console(): bool
    {
        return false;
    }

    /**
     * @return array<string, mixed>
     */
    protected function optionOverrides(): array
    {
        return [];
    }

    /**
     * @return array<string, mixed>
     */
    protected function componentOverrides(): array
    {
        return [];
    }

    /**
     * @return array<string, mixed>
     */
    protected function appOverrides(): array
    {
        return [];
    }

    protected function component(): IndexNowComponent
    {
        $component = $this->app->get('indexnow');
        \assert($component instanceof IndexNowComponent);

        return $component;
    }

    protected function kit(): IndexNowKit
    {
        return $this->component()->kit();
    }

    /**
     * @return list<string>
     */
    protected function sentUrls(): array
    {
        $urls = [];
        foreach ($this->transport->posts as $post) {
            /** @var list<string> $list */
            $list = $post['body']['urlList'];
            $urls = [...$urls, ...$list];
        }

        return $urls;
    }
}
