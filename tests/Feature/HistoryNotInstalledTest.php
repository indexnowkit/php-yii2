<?php

declare(strict_types=1);

namespace IndexNowKit\Yii2\Tests\Feature;

use IndexNowKit\Console\ExitCode;
use IndexNowKit\Http\Response;
use IndexNowKit\Yii2\Config\ConfigFactory;
use IndexNowKit\Yii2\Console\IndexNowController;
use IndexNowKit\Yii2\Tests\Fixtures\Post;
use IndexNowKit\Yii2\Tests\Yii2TestCase;
use LogicException;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * indexnowkit/history not installed (the component's `historyInstalled` property set to false) while a `history`
 * block is configured: the block is ignored as a whole, `check` says so, `indexnow/history` and `indexnow/status`
 * print the install line and exit 1, `historyConfig()` throws, nothing is recorded.
 */
final class HistoryNotInstalledTest extends Yii2TestCase
{
    private BufferedOutput $output;

    protected function console(): bool
    {
        return true;
    }

    protected function optionOverrides(): array
    {
        return ['history' => ['store' => 'pdo', 'pdo' => ['tabel' => 'x']]];
    }

    protected function componentOverrides(): array
    {
        return ['historyInstalled' => false];
    }

    protected function appOverrides(): array
    {
        $this->output = new BufferedOutput();

        return ['controllerMap' => ['indexnow' => ['class' => IndexNowController::class, 'output' => $this->output]]];
    }

    #[TestDox('check prints the ignored-block line; history and status print the install line and exit 1')]
    public function testCheckAndTheActions(): void
    {
        $this->transport
            ->onGet('https://www.example.com/' . self::KEY . '.txt', new Response(200, self::KEY, headers: ['Content-Type' => 'text/plain']))
            ->onGet('https://example.de/' . self::SECOND_KEY . '.txt', new Response(200, self::SECOND_KEY, headers: ['Content-Type' => 'text/plain']));

        [$code, $output] = $this->yii('indexnow/check');
        self::assertSame(ExitCode::SUCCESS, $code, $output);
        self::assertStringContainsString('history: not installed, the history block in the configuration is ignored (composer require indexnowkit/history)', $output);
        self::assertSame([], $this->logger->messages('warning'), 'the history block (with a typo) is ignored as a whole');
        self::assertSame([], ConfigFactory::factory($this->component()->options, false, sitemapInstalled: true, verifyInstalled: true, historyInstalled: false)->unknownOptions($this->component()->options));
        [$code] = $this->yii('indexnow/check', ['strict' => true]);
        self::assertSame(ExitCode::FAILURE, $code, 'an ignored block is a warning');

        foreach (['indexnow/history', 'indexnow/status'] as $route) {
            [$code, $output] = $this->yii($route, ['json' => true]);
            self::assertSame(ExitCode::FAILURE, $code);
            self::assertSame('indexnowkit/history is not installed: composer require indexnowkit/history', trim($output));
        }
    }

    public function testNothingIsRecordedAndHistoryConfigThrows(): void
    {
        $component = $this->component();
        (new Post(['slug' => 'plain']))->save(false);
        $this->kit()->flush();

        self::assertSame(['https://www.example.com/posts/plain'], $this->sentUrls());
        self::assertNull($component->submissionStore());
        self::assertFalse($component->historyEnabled());

        [, $output] = $this->yii('indexnow/config', ['json' => true]);
        $decoded = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertArrayNotHasKey('history', $decoded);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('indexnowkit/history is not installed: composer require indexnowkit/history');
        $component->historyConfig();
    }

    /**
     * @param array<array-key, mixed> $params
     *
     * @return array{0: int, 1: string}
     */
    private function yii(string $route, array $params = []): array
    {
        \assert($this->app instanceof \yii\console\Application);
        $code = $this->app->runAction($route, $params);

        return [\is_int($code) ? $code : 0, $this->output->fetch()];
    }
}
