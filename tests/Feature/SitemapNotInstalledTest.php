<?php

declare(strict_types=1);

namespace IndexNowKit\Yii2\Tests\Feature;

use IndexNowKit\Console\ExitCode;
use IndexNowKit\Yii2\Config\ConfigFactory;
use IndexNowKit\Yii2\Console\IndexNowController;
use IndexNowKit\Yii2\Sitemap\SitemapSupport;
use IndexNowKit\Yii2\Tests\Yii2TestCase;
use LogicException;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * indexnowkit/sitemap not installed (the predicate forced to false): `indexnow/sitemap` prints the install line and
 * exits 1, `indexnow/check` prints one line about it, the `sitemap` block of the fixtures warns about nothing,
 * `sitemapConfig()` / `sitemapSource()` throw, everything else works and nothing is logged.
 */
final class SitemapNotInstalledTest extends Yii2TestCase
{
    private BufferedOutput $output;

    protected function setUp(): void
    {
        SitemapSupport::$installed = false;
        parent::setUp();
    }

    protected function tearDown(): void
    {
        SitemapSupport::$installed = null;
        parent::tearDown();
    }

    protected function console(): bool
    {
        return true;
    }

    protected function optionOverrides(): array
    {
        return ['sitemap' => ['spol' => 'disk']]; // a typo the package would warn about: ignored without it
    }

    protected function appOverrides(): array
    {
        $this->output = new BufferedOutput();

        return ['controllerMap' => ['indexnow' => ['class' => IndexNowController::class, 'output' => $this->output]]];
    }

    #[TestDox('indexnow/sitemap accepts the argument of the real action, prints the install line and exits 1')]
    public function testStubAction(): void
    {
        [$code, $output] = $this->yii('indexnow/sitemap', ['https://www.example.com/sitemap.xml', 'dry-run' => true]);

        self::assertSame(ExitCode::FAILURE, $code);
        self::assertStringContainsString(SitemapSupport::NOT_INSTALLED, $output);
        self::assertSame([], $this->transport->posts);
    }

    #[TestDox('indexnow/check says the block is ignored (the fixtures have one); the other actions work')]
    public function testCheckAndOtherActions(): void
    {
        [, $output] = $this->yii('indexnow/check');
        self::assertStringContainsString(SitemapSupport::CHECK_MISSING_BLOCK_IGNORED, $output);
        self::assertStringNotContainsString('spool', $output, 'no spool line, no unknown option line');
        self::assertSame(SitemapSupport::CHECK_MISSING, SitemapSupport::checkLine([]), 'no block: the plain line');

        [$code, $output] = $this->yii('indexnow/submit', ['/a', 'dry-run' => true]);
        self::assertSame(ExitCode::SUCCESS, $code);
        self::assertStringContainsString('dry_run', $output);
    }

    #[TestDox('sitemapConfig() and sitemapSource() throw the install line; the options with the sitemap block build without a warning; nothing is logged')]
    public function testSilentWithoutThePackage(): void
    {
        $component = $this->component();
        self::assertFalse($component->sitemapInstalled());
        self::assertTrue($component->config()->enabled);
        self::assertSame([], ConfigFactory::factory($component->options, false)->unknownOptions($component->options));
        self::assertSame([], $component->urlsForAll([]), 'the graph builds without the package');
        self::assertSame([], $this->logger->messages('warning'), 'the sitemap block (with a typo) is ignored as a whole');
        self::assertSame([], $this->logger->messages('critical'));
        self::assertSame([], $this->logger->messages('error'));

        try {
            $component->sitemapConfig();
            self::fail('expected a LogicException');
        } catch (LogicException $e) {
            self::assertSame(SitemapSupport::NOT_INSTALLED, $e->getMessage());
        }
        $this->expectException(LogicException::class);
        $component->sitemapSource();
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
