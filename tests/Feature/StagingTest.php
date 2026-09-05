<?php

declare(strict_types=1);

namespace IndexNowKit\Yii2\Tests\Feature;

use IndexNowKit\Http\Response;
use IndexNowKit\Testing\Conformance\CheckOutputAssertions;
use IndexNowKit\Yii2\Console\IndexNowController;
use IndexNowKit\Yii2\Tests\Yii2TestCase;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * A staging copy with the production key and no `dry_run` option submits real URLs: `indexnow/check` must fail on
 * it (YII_ENV "test" is not in production_environments).
 */
final class StagingTest extends Yii2TestCase
{
    private BufferedOutput $output;

    protected function console(): bool
    {
        return true;
    }

    protected function optionOverrides(): array
    {
        return ['dry_run' => null];
    }

    protected function appOverrides(): array
    {
        $this->output = new BufferedOutput();

        return ['controllerMap' => ['indexnow' => ['class' => IndexNowController::class, 'output' => $this->output]]];
    }

    #[TestDox('check outside production with a key and dry_run unset -> exit 1 and the staging error')]
    public function testCheckFailsOutsideProductionWhenDryRunIsUnset(): void
    {
        $this->transport->onGet('https://www.example.com/' . self::KEY . '.txt', new Response(200, self::KEY));
        $this->transport->onGet('https://example.de/' . self::SECOND_KEY . '.txt', new Response(200, self::SECOND_KEY));
        \assert($this->app instanceof \yii\console\Application);

        $code = $this->app->runAction('indexnow/check');
        $display = $this->output->fetch();

        CheckOutputAssertions::assertExitCode(1, \is_int($code) ? $code : 0, $display);
        self::assertStringContainsString('✘ environment "test" is not in production_environments but dry_run is off: changes WILL be sent to search engines under key', $display);
        self::assertStringContainsString('Set INDEXNOW_DRY_RUN=1 or INDEXNOW_ENABLED=0 outside production', $display);
        self::assertStringNotContainsString(self::KEY, $display, 'the key is masked');
    }
}
