<?php

declare(strict_types=1);

namespace IndexNowKit\Yii2\Tests\Feature;

use IndexNowKit\Exception\ConfigurationException;
use IndexNowKit\History\Pdo\PdoSubmissionStore;
use IndexNowKit\History\Psr16SubmissionStore;
use IndexNowKit\Http\TransportInterface;
use IndexNowKit\Yii2\IndexNowComponent;
use IndexNowKit\Yii2\References;
use IndexNowKit\Yii2\Tests\Support\Fixtures;
use IndexNowKit\Yii2\Tests\Yii2TestCase;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * A mistake in the `indexnow` configuration is the family's `Exception\ConfigurationException`, whatever part of the
 * adapter finds it: an override that names nothing, a `queue.component` that is not a queue, a `history.store` whose
 * connection or cache does not resolve. Yii's own `InvalidConfigException` stays where Yii itself throws it.
 */
final class ConfigurationErrorsTest extends Yii2TestCase
{
    protected function optionOverrides(): array
    {
        return ['history' => ['store' => 'psr16']];
    }

    #[TestDox('an override that is neither an instance, an array, a class nor a component id names the value')]
    public function testOverrideOfTheWrongShape(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('an override must be an instance, a config array, a class name or a component id, got int');
        References::reference(42);
    }

    #[TestDox('an override naming a component that does not exist names the id and the type it had to be')]
    public function testOverrideNamingNothing(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('"no-such-transport" is not a ' . TransportInterface::class);
        References::ensure('no-such-transport', TransportInterface::class);
    }

    #[TestDox('queue.component naming a component that is not a yii2-queue is a configuration error naming the option')]
    public function testQueueComponentOfTheWrongType(): void
    {
        $component = $this->rebuild(['dispatch' => 'queue', 'queue' => ['component' => 'cache']]);
        $component->services()->dispatcher()->dispatch(['https://www.example.com/posts/x']);

        // the dispatcher never throws into the flush: the exception is the payload of its one error line
        $thrown = null;
        foreach ($this->logger->records as $record) {
            $thrown = $record['context']['exception'] ?? $thrown;
        }
        self::assertInstanceOf(ConfigurationException::class, $thrown);
        self::assertStringContainsString('component "cache" (queue.component) is not a yii\queue\Queue', $thrown->getMessage());
    }

    #[TestDox('history.store psr16 uses the cache component behind debounce.store, the default cache when it names none')]
    public function testPsr16StoreOverTheDefaultCache(): void
    {
        self::assertInstanceOf(Psr16SubmissionStore::class, $this->component()->submissionStore());
    }

    #[TestDox('history.store psr16 with a debounce.store cache component that does not exist names the id')]
    public function testPsr16StoreWithoutItsCache(): void
    {
        $component = $this->rebuild(['debounce' => ['per_url' => 600, 'store' => 'no-such-cache']]);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('history.store "psr16" needs a PSR-16 cache under "no-such-cache"');
        $component->submissionStore();
    }

    #[TestDox('history.store pdo whose history.pdo.service is not a db connection names the option')]
    public function testPdoStoreWithoutAConnection(): void
    {
        $component = $this->rebuild(['history' => ['store' => 'pdo', 'pdo' => ['service' => 'cache']]]);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('history.pdo.service "cache" does not give a PDO connection');
        $component->submissionStore();
    }

    #[TestDox('history.store pdo over the default db component is the pdo store')]
    public function testPdoStoreOverTheDefaultConnection(): void
    {
        self::assertInstanceOf(PdoSubmissionStore::class, $this->rebuild(['history' => ['store' => 'pdo']])->submissionStore());
    }

    /**
     * A second application with other options: the component reads `options` once, so an option a test wants to
     * change has to be there before the first `config()`.
     *
     * @param array<string, mixed> $optionOverrides
     */
    private function rebuild(array $optionOverrides): IndexNowComponent
    {
        Fixtures::destroy();
        $this->app = Fixtures::webApp($this->transport, $this->logger, Fixtures::merge($this->optionOverrides(), $optionOverrides));

        return $this->component();
    }
}
