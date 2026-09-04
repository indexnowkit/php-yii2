<?php

declare(strict_types=1);

namespace IndexNowKit\Yii2\Log;

use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Stringable;
use Throwable;
use yii\log\Logger;

/**
 * PSR-3 over Yii's logger: every IndexNow line lands in `Yii::getLogger()` under one category (`logging.category`,
 * default `indexnow`), so `log.targets[].categories` routes it. Placeholders are interpolated here (Yii's logger
 * does not know PSR-3 context); an `exception` context entry is appended as `Class: message`.
 */
final class YiiLogger extends AbstractLogger
{
    private const LEVELS = [
        LogLevel::EMERGENCY => Logger::LEVEL_ERROR,
        LogLevel::ALERT => Logger::LEVEL_ERROR,
        LogLevel::CRITICAL => Logger::LEVEL_ERROR,
        LogLevel::ERROR => Logger::LEVEL_ERROR,
        LogLevel::WARNING => Logger::LEVEL_WARNING,
        LogLevel::NOTICE => Logger::LEVEL_INFO,
        LogLevel::INFO => Logger::LEVEL_INFO,
        LogLevel::DEBUG => Logger::LEVEL_TRACE,
    ];

    public function __construct(private readonly Logger $logger, private readonly string $category = 'indexnow') {}

    /**
     * @param mixed        $level
     * @param array<mixed> $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $text = self::interpolate((string) $message, $context);
        $exception = $context['exception'] ?? null;
        if ($exception instanceof Throwable) {
            $text .= \sprintf(' [%s: %s]', $exception::class, $exception->getMessage());
        }
        $this->logger->log($text, self::LEVELS[\is_string($level) ? $level : LogLevel::INFO] ?? Logger::LEVEL_INFO, $this->category);
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function interpolate(string $message, array $context): string
    {
        $replace = [];
        foreach ($context as $key => $value) {
            $replace['{' . $key . '}'] = match (true) {
                $value === null => 'null',
                \is_bool($value) => $value ? 'true' : 'false',
                \is_scalar($value), $value instanceof Stringable => (string) $value,
                \is_array($value) => (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                default => get_debug_type($value),
            };
        }

        return strtr($message, $replace);
    }
}
