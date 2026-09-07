<?php

declare(strict_types=1);

namespace IndexNowKit\Yii2\Tests\Feature;

use IndexNowKit\Config;
use IndexNowKit\Console\ExitCode;
use IndexNowKit\Testing\Conformance\OptionalPackageAssertions;
use IndexNowKit\Yii2\Console\IndexNowController;
use IndexNowKit\Yii2\Tests\Fixtures\Post;
use IndexNowKit\Yii2\Tests\Yii2TestCase;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * The optional packages left to detection (the component's `sitemapInstalled` / `verifyInstalled` /
 * `historyInstalled` properties at their default null): the application boots, the config builds, `indexnow/check`
 * names exactly the packages that are absent, a hook submits. With the packages in `vendor/` this is the ordinary
 * boot; the CI job `optional-packages-absent` runs it after `composer remove` of the three packages, where a config
 * factory that loaded a class of the package to ask about it was a fatal.
 */
final class OptionalPackagesDetectionTest extends Yii2TestCase
{
    private BufferedOutput $output;

    protected function console(): bool
    {
        return true;
    }

    protected function appOverrides(): array
    {
        $this->output = new BufferedOutput();

        return ['controllerMap' => ['indexnow' => ['class' => IndexNowController::class, 'output' => $this->output]]];
    }

    #[TestDox('the config builds with detection; check names the absent packages; a hook submits')]
    public function testDetection(): void
    {
        self::assertInstanceOf(Config::class, $this->component()->config());
        self::assertTrue($this->component()->config()->enabled);

        \assert($this->app instanceof \yii\console\Application);
        $code = $this->app->runAction('indexnow/check');
        $display = $this->output->fetch();
        OptionalPackageAssertions::assertDetected($display);
        self::assertContains(\is_int($code) ? $code : 0, [ExitCode::SUCCESS, ExitCode::FAILURE], $display);

        $post = new Post(['slug' => 'detected']);
        self::assertTrue($post->save(false));
        $this->kit()->flush();
        self::assertSame(['https://www.example.com/posts/detected'], $this->sentUrls());
        self::assertSame([], $this->logger->messages('critical'));
        self::assertSame([], $this->logger->messages('error'));
    }
}
