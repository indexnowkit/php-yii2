<?php

declare(strict_types=1);

namespace IndexNowKit\Yii2\Console;

use Symfony\Component\Console\Output\Output;
use yii\console\Controller;

/**
 * The symfony/console output the runners write to, routed through the Yii controller: every write goes to
 * `Controller::stdout()`, so `--color` (`isColorEnabled()`), a subclass that captures or redirects `stdout()` and the
 * Yii conventions for a console controller all apply. The formatter decorates (`<info>`, `<error>`) exactly when Yii
 * would colour its own output.
 */
final class ControllerOutput extends Output
{
    public function __construct(private readonly Controller $controller, int $verbosity = self::VERBOSITY_NORMAL)
    {
        parent::__construct($verbosity, $controller->isColorEnabled());
    }

    protected function doWrite(string $message, bool $newline): void
    {
        $this->controller->stdout($message . ($newline ? \PHP_EOL : ''));
    }
}
