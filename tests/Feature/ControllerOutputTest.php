<?php

declare(strict_types=1);

namespace IndexNowKit\Yii2\Tests\Feature;

use IndexNowKit\Yii2\Console\ControllerOutput;
use IndexNowKit\Yii2\Console\IndexNowController;
use IndexNowKit\Yii2\Tests\Yii2TestCase;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\Console\Output\OutputInterface;
use yii\console\Controller;

/** A controller whose stdout() is captured: what an application that redirects console output does. */
final class CapturingController extends Controller
{
    /** @var list<string> */
    public array $written = [];

    public function stdout($string): int|false
    {
        $this->written[] = (string) $string;

        return \strlen((string) $string);
    }
}

final class ControllerOutputTest extends Yii2TestCase
{
    #[TestDox('W3: ControllerOutput writes through Controller::stdout(), decorated exactly when --color says so; the newline travels with the line')]
    public function testControllerOutputWritesThroughStdout(): void
    {
        $controller = new CapturingController('capture', $this->app);
        $controller->color = false;
        $output = new ControllerOutput($controller, OutputInterface::VERBOSITY_VERBOSE);
        self::assertFalse($output->isDecorated(), 'decorated follows isColorEnabled()');
        self::assertTrue($output->isVerbose());
        $output->writeln('<info>hello</info>');
        $output->write('a');
        self::assertSame(['hello' . \PHP_EOL, 'a'], $controller->written, 'tags are stripped when not decorated');

        $controller = new CapturingController('capture', $this->app);
        $controller->color = true;
        $output = new ControllerOutput($controller);
        self::assertTrue($output->isDecorated());
        $output->writeln('<info>hi</info>');
        self::assertStringContainsString("\033[", $controller->written[0], 'ANSI codes when colour is on');
    }

    #[TestDox('W3: without an injected output the command builds a ControllerOutput over itself (SHELL_VERBOSITY=-1 keeps the run quiet)')]
    public function testTheDefaultOutputIsAControllerOutput(): void
    {
        \assert($this->app instanceof \yii\console\Application);
        $previous = getenv('SHELL_VERBOSITY');
        putenv('SHELL_VERBOSITY=-1');
        try {
            [$controller, $action] = $this->app->createController('indexnow/check');
            \assert($controller instanceof IndexNowController);
            self::assertNull($controller->output);
            self::assertIsInt($controller->runAction($action));
            self::assertInstanceOf(ControllerOutput::class, $controller->output);
            self::assertTrue($controller->output->isQuiet());
        } finally {
            putenv($previous === false ? 'SHELL_VERBOSITY' : 'SHELL_VERBOSITY=' . $previous);
        }
    }

    protected function console(): bool
    {
        return true;
    }

    protected function appOverrides(): array
    {
        return ['controllerMap' => ['indexnow' => ['class' => IndexNowController::class]]];
    }
}
