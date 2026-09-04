<?php

declare(strict_types=1);

namespace IndexNowKit\Yii2\Tests\Unit;

use IndexNowKit\Exception\ConfigurationException;
use IndexNowKit\Testing\ArrayLogger;
use IndexNowKit\Yii2\Config\ConfigFactory;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

final class ConfigFactoryTest extends TestCase
{
    private const KEY = 'abcdef1234567890abcdef1234567890';

    #[TestDox('dispatch auto is queue with a queue component and sync without; Yii blocks are stripped, unknown keys warned')]
    public function testDispatchAutoAndBlocks(): void
    {
        $logger = new ArrayLogger();
        $options = ['key' => self::KEY, 'base_url' => 'https://www.example.com', 'queue' => ['component' => 'q'], 'router' => ['languages' => ['en']], 'active_record' => ['models' => []], 'key_file' => ['enabled' => false], 'debounce' => ['store' => 'memory', 'per_url' => 5], 'logging' => ['category' => 'x'], 'http' => ['client' => null], 'typo' => 1];

        $config = ConfigFactory::create($options, 'prod', true, $logger);
        self::assertSame('queue', $config->dispatch);
        self::assertFalse($config->serveKeyFile);
        self::assertSame(5, $config->debouncePerUrl);
        self::assertSame('prod', $config->environment);
        self::assertCount(1, $logger->messages('warning'));
        self::assertStringContainsString('typo', $logger->messages('warning')[0]);

        self::assertSame('sync', ConfigFactory::create($options, 'prod', false)->dispatch);
    }

    #[TestDox('queue without base_url, queue without the component and an unknown dispatch are errors; create() turns them into disabled + critical')]
    public function testErrors(): void
    {
        $this->expectException(ConfigurationException::class);
        ConfigFactory::build(['key' => self::KEY, 'dispatch' => 'queue'], 'prod', true);
    }

    public function testQueueWithoutComponent(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageMatches('/queue component does not exist/');
        ConfigFactory::build(['key' => self::KEY, 'base_url' => 'https://www.example.com', 'dispatch' => 'queue'], 'prod', false);
    }

    public function testUnknownDispatch(): void
    {
        $this->expectException(ConfigurationException::class);
        ConfigFactory::build(['key' => self::KEY, 'dispatch' => 'later'], 'prod', false);
    }

    public function testCreateDisablesOnError(): void
    {
        $logger = new ArrayLogger();
        $config = ConfigFactory::create(['key' => 'short'], 'prod', false, $logger);

        self::assertFalse($config->enabled);
        self::assertTrue($config->dryRun);
        self::assertCount(1, $logger->messages('critical'));
    }
}
