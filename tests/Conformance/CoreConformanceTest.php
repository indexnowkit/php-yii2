<?php

declare(strict_types=1);

namespace IndexNowKit\Yii2\Tests\Conformance;

use IndexNowKit\IndexNowKit;
use IndexNowKit\Testing\ArrayLogger;
use IndexNowKit\Testing\Conformance\CoreConformanceTestCase;
use IndexNowKit\Testing\FakeTransport;
use IndexNowKit\Yii2\IndexNowComponent;
use IndexNowKit\Yii2\Tests\Support\Fixtures;
use Yii;

/**
 * The core conformance kit against the facade the component builds from its options.
 */
final class CoreConformanceTest extends CoreConformanceTestCase
{
    private FakeTransport $transport;

    protected function setUp(): void
    {
        $this->transport = new FakeTransport();
        Fixtures::webApp($this->transport, new ArrayLogger());
    }

    protected function tearDown(): void
    {
        Fixtures::destroy();
    }

    protected function kit(): IndexNowKit
    {
        $component = Yii::$app?->get('indexnow');
        \assert($component instanceof IndexNowComponent);

        return $component->kit();
    }

    protected function transport(): FakeTransport
    {
        return $this->transport;
    }

    protected function secondHost(): ?string
    {
        return 'example.de';
    }
}
