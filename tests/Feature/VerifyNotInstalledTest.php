<?php

declare(strict_types=1);

namespace IndexNowKit\Yii2\Tests\Feature;

use IndexNowKit\Console\ExitCode;
use IndexNowKit\Http\Response;
use IndexNowKit\Submitter;
use IndexNowKit\Verify\VerifyingSubmitterFactory;
use IndexNowKit\Yii2\Config\ConfigFactory;
use IndexNowKit\Yii2\Console\IndexNowController;
use IndexNowKit\Yii2\Tests\Fixtures\Post;
use IndexNowKit\Yii2\Tests\Yii2TestCase;
use LogicException;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * indexnowkit/verify not installed (the component's `verifyInstalled` property set to false) while a `verify` block
 * is configured: the block is ignored as a whole, `check` says so, `--sample` is an error naming the install line,
 * `verifyConfig()` throws, nothing is fetched before a submission.
 */
final class VerifyNotInstalledTest extends Yii2TestCase
{
    private BufferedOutput $output;

    protected function console(): bool
    {
        return true;
    }

    protected function optionOverrides(): array
    {
        return ['verify' => ['enabled' => true, 'redirekt' => 'follow']];
    }

    protected function componentOverrides(): array
    {
        return ['verifyInstalled' => false];
    }

    protected function appOverrides(): array
    {
        $this->output = new BufferedOutput();

        return ['controllerMap' => ['indexnow' => ['class' => IndexNowController::class, 'output' => $this->output]]];
    }

    #[TestDox('check prints the ignored-block line; --sample is an error with the install line')]
    public function testCheck(): void
    {
        $this->transport
            ->onGet('https://www.example.com/' . self::KEY . '.txt', new Response(200, self::KEY, headers: ['Content-Type' => 'text/plain']))
            ->onGet('https://example.de/' . self::SECOND_KEY . '.txt', new Response(200, self::SECOND_KEY, headers: ['Content-Type' => 'text/plain']));

        [$code, $output] = $this->yii('indexnow/check');
        self::assertSame(ExitCode::SUCCESS, $code, $output);
        self::assertStringContainsString('verify: not installed, the verify block in the configuration is ignored (composer require indexnowkit/verify) — pre-flight checks off', $output);

        [$code, $output] = $this->yii('indexnow/check', ['sample' => ['https://www.example.com/x']]);
        self::assertSame(ExitCode::FAILURE, $code);
        self::assertStringContainsString('check --sample needs indexnowkit/verify (composer require indexnowkit/verify)', $output);
        self::assertNotContains('https://www.example.com/x', $this->transport->gets);
        self::assertSame([], $this->logger->messages('warning'), 'the verify block (with a typo) is ignored as a whole');
        self::assertSame([], ConfigFactory::factory($this->component()->options, false, sitemapInstalled: true, verifyInstalled: false)->unknownOptions($this->component()->options));
    }

    public function testThePlainSubmitterAndNothingFetched(): void
    {
        $component = $this->component();
        (new Post(['slug' => 'plain']))->save(false);
        $this->kit()->flush();

        self::assertSame(['https://www.example.com/posts/plain'], $this->sentUrls());
        self::assertSame([], $this->transport->gets);
        self::assertInstanceOf(Submitter::class, $component->submitter());
        self::assertNotInstanceOf(VerifyingSubmitterFactory::class, $component->submitterFactory());
        self::assertFalse($component->verifyEnabled());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('indexnowkit/verify is not installed: composer require indexnowkit/verify');
        $component->verifyConfig();
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
