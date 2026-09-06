<?php

declare(strict_types=1);

namespace IndexNowKit\Yii2\Tests\Feature;

use IndexNowKit\Console\ExitCode;
use IndexNowKit\History\Pdo\Schema;
use IndexNowKit\Http\Response;
use IndexNowKit\Yii2\Console\IndexNowController;
use IndexNowKit\Yii2\Tests\Yii2TestCase;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * `php yii indexnow/history`, `indexnow/status`, `indexnow/check` and `indexnow/config` with indexnowkit/history and
 * `history.store: pdo` over the `db` component: the recorded submissions, the filters, --purge, the status (text and
 * JSON per the package's schema), the check lines, the config section — and a missing table as a check error.
 */
final class HistoryConsoleTest extends Yii2TestCase
{
    private BufferedOutput $output;

    protected function console(): bool
    {
        return true;
    }

    protected function optionOverrides(): array
    {
        return ['history' => ['store' => 'pdo', 'retention_days' => 30]];
    }

    protected function appOverrides(): array
    {
        $this->output = new BufferedOutput();

        return ['controllerMap' => ['indexnow' => ['class' => IndexNowController::class, 'output' => $this->output]]];
    }

    #[TestDox('history lists a recorded submission (table, --json, filters) and --purge runs the retention')]
    public function testHistory(): void
    {
        $this->createTable();
        $this->kit()->submit(['https://www.example.com/posts/one', 'https://www.example.com/posts/two']);
        self::assertCount(2, $this->sentUrls());

        [$code, $output] = $this->yii('indexnow/history');
        self::assertSame(ExitCode::SUCCESS, $code, $output);
        self::assertStringContainsString('https://www.example.com/posts/one', $output);
        self::assertStringContainsString('https://www.example.com/posts/two', $output);
        self::assertStringContainsString('1 record(s)', $output);

        [$code, $output] = $this->yii('indexnow/history', ['json' => true]);
        self::assertSame(ExitCode::SUCCESS, $code);
        $decoded = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertCount(1, $decoded['records']);
        self::assertSame(['ok', 'api', 200], [$decoded['records'][0]['status'], $decoded['records'][0]['engine'], $decoded['records'][0]['http_status']]);
        self::assertCount(2, $decoded['records'][0]['urls']);

        [, $output] = $this->yii('indexnow/history', ['url' => 'https://www.example.com/posts/two?utm_source=x']);
        self::assertStringContainsString('1 record(s)', $output, '--url is normalized the way the submission was');
        [, $output] = $this->yii('indexnow/history', ['host' => ['example.de']]);
        self::assertStringContainsString('No records match.', $output);
        [, $output] = $this->yii('indexnow/history', ['status' => 'skipped']);
        self::assertStringContainsString('No records match.', $output);
        [, $output] = $this->yii('indexnow/history', ['limit' => '1', 'json' => true]);
        self::assertCount(1, json_decode($output, true, flags: JSON_THROW_ON_ERROR)['records'] ?? []);

        [$code, $output] = $this->yii('indexnow/history', ['purge' => true]);
        self::assertSame(ExitCode::SUCCESS, $code, $output);
        self::assertMatchesRegularExpression('/^purged 0 records older than \d{4}-/', trim($output));
        [, $output] = $this->yii('indexnow/history', ['purge' => '7']);
        self::assertStringContainsString('purged 0 records older than', $output);
    }

    public function testStatusCheckAndConfig(): void
    {
        $this->createTable();
        $this->transport
            ->onGet('https://www.example.com/' . self::KEY . '.txt', new Response(200, self::KEY, headers: ['Content-Type' => 'text/plain']))
            ->onGet('https://example.de/' . self::SECOND_KEY . '.txt', new Response(200, self::SECOND_KEY, headers: ['Content-Type' => 'text/plain']));

        [$code, $output] = $this->yii('indexnow/status');
        self::assertSame(ExitCode::SUCCESS, $code, $output);
        self::assertStringContainsString('dispatch: sync', $output);
        self::assertStringContainsString('debounce: off, store memory', $output);
        self::assertStringContainsString('www.example.com: 0 consecutive 403', $output);
        self::assertStringContainsString('history: 0 records, no successful submission recorded', $output);

        [$code, $output] = $this->yii('indexnow/status', ['json' => true]);
        self::assertSame(ExitCode::SUCCESS, $code);
        $decoded = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertStatusFollowsTheSchema($decoded);
        self::assertSame(['mode' => 'sync', 'adapter' => []], $decoded['dispatch']);
        self::assertSame(['per_url' => 0, 'store' => 'memory'], $decoded['debounce']);
        self::assertSame(['store' => 'history', 'records' => 0, 'last_success' => null, 'error' => null], $decoded['history']);

        [$code, $output] = $this->yii('indexnow/check');
        self::assertSame(ExitCode::SUCCESS, $code, $output);
        self::assertStringContainsString('history: pdo store (indexnow_submissions)', $output);
        self::assertStringContainsString('history: no records yet', $output);

        [$code, $output] = $this->yii('indexnow/config', ['json' => true]);
        self::assertSame(ExitCode::SUCCESS, $code);
        $decoded = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertSame(['store' => 'pdo', 'limit' => 500, 'key_prefix' => null, 'pdo' => ['dsn' => null, 'service' => null, 'table' => 'indexnow_submissions'], 'retention_days' => 30], $decoded['history']);
        self::assertArrayNotHasKey('history', $decoded['adapter']);
    }

    #[TestDox('without the table, check prints the history.store error with the migration hint and status reports the error')]
    public function testMissingTableIsACheckError(): void
    {
        $this->transport
            ->onGet('https://www.example.com/' . self::KEY . '.txt', new Response(200, self::KEY, headers: ['Content-Type' => 'text/plain']))
            ->onGet('https://example.de/' . self::SECOND_KEY . '.txt', new Response(200, self::SECOND_KEY, headers: ['Content-Type' => 'text/plain']));

        [$code, $output] = $this->yii('indexnow/check', ['json' => true]);
        self::assertSame(ExitCode::FAILURE, $code);
        $decoded = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        $items = array_values(array_filter($decoded['items'], static fn(array $i): bool => $i['code'] === 'history.store'));
        self::assertCount(1, $items);
        self::assertSame('error', $items[0]['level']);
        self::assertStringContainsString('docs/migrations.md', $items[0]['message']);

        [$code, $output] = $this->yii('indexnow/status', ['json' => true]);
        self::assertSame(ExitCode::SUCCESS, $code, 'status stays read-only and exit 0; the error is a field');
        $decoded = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertNotNull($decoded['history']['error']);
    }

    private function createTable(): void
    {
        foreach (Schema::sql('sqlite') as $sql) {
            $this->app->getDb()->createCommand($sql)->execute();
        }
    }

    /**
     * The required members of packages/history/docs/status.schema.json, top level and one level down (the schema
     * validator lives in the history package's tests; here the shape of what the adapter wires is enough).
     *
     * @param array<string, mixed> $status
     */
    public static function assertStatusFollowsTheSchema(array $status): void
    {
        // The `required` members of the schema, copied: the history package is not a sibling directory in the split repository.
        $required = [
            '' => ['enabled', 'dry_run', 'environment', 'dispatch', 'debounce', 'engines', 'forbidden_escalation', 'hosts', 'history', 'core'],
            'dispatch' => ['mode', 'adapter'],
            'debounce' => ['per_url', 'store'],
            'history' => ['store', 'records', 'last_success', 'error'],
        ];
        foreach ($required[''] as $key) {
            self::assertArrayHasKey($key, $status);
        }
        foreach (['dispatch', 'debounce', 'history'] as $section) {
            self::assertIsArray($status[$section]);
            foreach ($required[$section] as $key) {
                self::assertArrayHasKey($key, $status[$section]);
            }
        }
        foreach ($status['hosts'] as $host) {
            self::assertSame(['host', 'forbidden', 'escalated'], array_keys($host));
        }
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
