<?php

declare(strict_types=1);

namespace IndexNowKit\Yii2\Tests\Feature;

use IndexNowKit\Console\ExitCode;
use IndexNowKit\Http\Response;
use IndexNowKit\Yii2\Console\IndexNowController;
use IndexNowKit\Yii2\Tests\Fixtures\Post;
use IndexNowKit\Yii2\Tests\Yii2TestCase;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * `php yii indexnow/check` and `indexnow/config` with indexnowkit/verify enabled: the verify lines, the `--sample` /
 * `--sample-class` reports (warnings only), the `verify` section of `config --json`.
 */
final class VerifyConsoleTest extends Yii2TestCase
{
    private BufferedOutput $output;

    protected function console(): bool
    {
        return true;
    }

    protected function optionOverrides(): array
    {
        return ['verify' => ['enabled' => true, 'redirect' => 'follow', 'user_agent' => 'test-verify/1']];
    }

    protected function appOverrides(): array
    {
        $this->output = new BufferedOutput();

        return ['controllerMap' => ['indexnow' => ['class' => IndexNowController::class, 'output' => $this->output]]];
    }

    #[TestDox('check prints the verify lines and the dispatch warning; --sample is a warning at most, --sample-class resolves records')]
    public function testCheck(): void
    {
        $this->transport
            ->onGet('https://www.example.com/' . self::KEY . '.txt', new Response(200, self::KEY, headers: ['Content-Type' => 'text/plain']))
            ->onGet('https://example.de/' . self::SECOND_KEY . '.txt', new Response(200, self::SECOND_KEY, headers: ['Content-Type' => 'text/plain']))
            ->onGet('https://www.example.com/posts/fine', new Response(200))
            ->onGet('https://www.example.com/posts/hidden', new Response(200, '<head><meta name="robots" content="noindex"></head>'));

        [$code, $output] = $this->yii('indexnow/check');
        self::assertSame(ExitCode::SUCCESS, $code, $output);
        self::assertStringContainsString('verify: enabled (redirect: follow, non_canonical: skip, origin_error: skip)', $output);
        self::assertStringContainsString('verify.enabled with dispatch: sync fetches your own pages inside the web request; use dispatch: queue', $output);
        self::assertStringContainsString('verify sample: no sample given', $output);

        [$code, $output] = $this->yii('indexnow/check', ['sample' => ['/posts/fine', 'https://www.example.com/posts/hidden'], 'json' => true]);
        self::assertSame(ExitCode::SUCCESS, $code, 'a noindex sample is a warning, not a failure');
        $decoded = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        $samples = array_values(array_filter($decoded['items'], static fn(array $i): bool => $i['code'] === 'verify.sample'));
        self::assertSame(['ok', 'verify sample https://www.example.com/posts/fine: HTTP 200, index, canonical: self, robots: allowed'], [$samples[0]['level'], $samples[0]['message']]);
        self::assertSame('warning', $samples[1]['level']);
        self::assertStringContainsString('noindex (meta robots)', $samples[1]['message']);

        (new Post(['slug' => 'fine']))->save(false);
        [$code, $output] = $this->yii('indexnow/check', ['sample-class' => [Post::class], 'json' => true]);
        self::assertSame(ExitCode::SUCCESS, $code, $output);
        $decoded = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertContains('verify sample https://www.example.com/posts/fine: HTTP 200, index, canonical: self, robots: allowed', array_column(array_filter($decoded['items'], static fn(array $i): bool => $i['code'] === 'verify.sample'), 'message'));
    }

    #[TestDox('indexnow/sitemap verifies through the decorated command factory; the no-verify flag takes the plain one')]
    public function testSitemapNoVerify(): void
    {
        $this->transport
            ->onGet('https://www.example.com/sitemap.xml', new Response(200, '<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>https://www.example.com/s1</loc></url><url><loc>https://www.example.com/s2</loc></url></urlset>'))
            ->onGet('https://www.example.com/s1', new Response(200))
            ->onGet('https://www.example.com/s2', new Response(200, '<head><meta name="robots" content="noindex"></head>'));

        [$code] = $this->yii('indexnow/sitemap', ['force' => true]);
        self::assertSame(ExitCode::SUCCESS, $code);
        self::assertSame(['https://www.example.com/s1'], $this->sentUrls(), 'the command factory is decorated');

        [$code] = $this->yii('indexnow/sitemap', ['force' => true, 'no-verify' => true]);
        self::assertSame(ExitCode::SUCCESS, $code);
        self::assertSame(['https://www.example.com/s1', 'https://www.example.com/s1', 'https://www.example.com/s2'], $this->sentUrls(), 'the plain factory submits everything');
    }

    public function testConfigJsonHasTheVerifySection(): void
    {
        [$code, $output] = $this->yii('indexnow/config', ['json' => true]);
        self::assertSame(ExitCode::SUCCESS, $code);
        $decoded = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertSame(['enabled' => true, 'redirect' => 'follow', 'non_canonical' => 'skip', 'origin_error' => 'skip', 'delay' => 0, 'timeout' => 5, 'max_redirects' => 3, 'max_batch' => 100, 'robots_cache_ttl' => 3600, 'user_agent' => 'test-verify/1'], $decoded['verify']);
        self::assertArrayNotHasKey('verify', $decoded['adapter']);
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
