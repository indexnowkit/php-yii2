<?php

declare(strict_types=1);

namespace IndexNowKit\Yii2\Tests\Feature;

use IndexNowKit\Http\Response;
use IndexNowKit\Testing\Conformance\CheckOutputAssertions;
use IndexNowKit\Yii2\Console\IndexNowController;
use IndexNowKit\Yii2\Tests\Fixtures\Post;
use IndexNowKit\Yii2\Tests\Yii2TestCase;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\Console\Output\BufferedOutput;

final class CommandsTest extends Yii2TestCase
{
    private BufferedOutput $output;

    protected function console(): bool
    {
        return true;
    }

    protected function appOverrides(): array
    {
        $this->output = new BufferedOutput();

        return ['controllerMap' => ['indexnow' => ['class' => IndexNowController::class, 'output' => $this->output, 'modelNamespaces' => ['IndexNowKit\\Yii2\\Tests\\Fixtures']]]];
    }

    #[TestDox('H04 indexnow/check with reachable key files -> exit 0, host, engine and wiring lines')]
    public function testCheckOk(): void
    {
        $this->stubKeyFiles();

        [$code, $output] = $this->yii('indexnow/check');

        CheckOutputAssertions::assertExitCode(0, $code, $output);
        CheckOutputAssertions::assertReady($output, 'www.example.com', 'example.de');
        foreach (['engines: api', 'dispatch "sync"', 'debounce: off', 'spooled in memory', 'active record: records using IndexNowBehavior and ' . \IndexNowKit\Yii2\Tests\Fixtures\ModelPost::class, 'key file: served by the web application'] as $expected) {
            self::assertStringContainsString($expected, $output);
        }
    }

    #[TestDox('H05 indexnow/check when the key file answers 403 -> exit 1 with the hint')]
    public function testCheckForbidden(): void
    {
        $this->transport->onGet('https://www.example.com/' . self::KEY . '.txt', new Response(403));
        $this->transport->onGet('https://example.de/' . self::SECOND_KEY . '.txt', new Response(200, self::SECOND_KEY));

        [$code, $output] = $this->yii('indexnow/check');

        CheckOutputAssertions::assertExitCode(1, $code, $output);
        CheckOutputAssertions::assertKeyFileHint($output, 403);
    }

    #[TestDox('indexnow/config --json prints the effective configuration with masked keys and the Yii-only keys')]
    public function testConfig(): void
    {
        [$code, $output] = $this->yii('indexnow/config', ['json' => true]);

        self::assertSame(0, $code, $output);
        $decoded = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertStringNotContainsString(self::KEY, $output);
        self::assertSame(\IndexNowKit\Key\KeyValidator::mask(self::KEY), $decoded['config']['key']);
        self::assertArrayHasKey('active_record', $decoded['adapter'], 'the Yii blocks are reported as given');
        self::assertArrayNotHasKey('key', $decoded['adapter']);

        [$code, $output] = $this->yii('indexnow/config');
        self::assertSame(0, $code);
        self::assertStringContainsString('debounce.per_url', $output);
        self::assertStringNotContainsString(self::KEY, $output);
    }

    #[TestDox('indexnow/check --host limits the key file check to one host')]
    public function testCheckOnlyHost(): void
    {
        $this->transport->onGet('https://example.de/' . self::SECOND_KEY . '.txt', new Response(200, self::SECOND_KEY));

        [$code] = $this->yii('indexnow/check', ['host' => 'example.de']);

        self::assertSame(0, $code);
        self::assertSame(['https://example.de/' . self::SECOND_KEY . '.txt', 'https://example.de/robots.txt'], $this->transport->gets, 'the key file and robots.txt of that host only');
    }

    #[TestDox('indexnow/key-generate prints a 32-char hex key; --alphanumeric --length change alphabet and length; --write-env writes and rotates')]
    public function testKeyGenerate(): void
    {
        [$code, $output] = $this->yii('indexnow/key-generate');
        self::assertSame(0, $code);
        self::assertMatchesRegularExpression('/INDEXNOW_KEY=[0-9a-f]{32}/', $output);
        self::assertStringContainsString('php yii indexnow/check', $output);

        [$code, $output] = $this->yii('indexnow/key-generate', ['length' => '16', 'alphanumeric' => true]);
        self::assertSame(0, $code);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9]{16}$/m', $output);

        $file = tempnam(sys_get_temp_dir(), 'env');
        self::assertIsString($file);
        try {
            [$code] = $this->yii('indexnow/key-generate', ['write-env' => $file]);
            self::assertSame(0, $code);
            $first = (string) file_get_contents($file);
            self::assertMatchesRegularExpression('/^INDEXNOW_KEY=[0-9a-f]{32}\n$/', $first);
            [$code, $output] = $this->yii('indexnow/key-generate', ['write-env' => $file]);
            self::assertSame(0, $code);
            self::assertStringContainsString('nothing to do', $output);
            [$code, $output] = $this->yii('indexnow/key-generate', ['write-env' => $file, 'force' => true]);
            self::assertSame(0, $code);
            self::assertStringContainsString('Rotating the key', $output);
            self::assertNotSame($first, file_get_contents($file));
        } finally {
            @unlink($file);
        }
    }

    #[TestDox('indexnow/submit sends the URLs and prints a table; --json prints results; --dry-run sends nothing; a failed engine answer gives exit 1')]
    public function testSubmit(): void
    {
        [$code, $output] = $this->yii('indexnow/submit', ['/a', 'https://www.example.com/b']);
        self::assertSame(0, $code, $output);
        self::assertMatchesRegularExpression('/\bapi\s+www\.example\.com\s+2\s+ok\b/', $output);
        self::assertSame(['https://www.example.com/a', 'https://www.example.com/b'], $this->sentUrls());

        [$code, $output] = $this->yii('indexnow/submit', ['/c', 'json' => true]);
        self::assertSame(0, $code);
        self::assertStringContainsString('"status": "ok"', $output);

        [$code, $output] = $this->yii('indexnow/submit', ['/d', 'dry-run' => true]);
        self::assertSame(0, $code);
        self::assertStringContainsString('dry_run', $output);
        self::assertCount(2, $this->transport->posts);

        $this->transport->willRespond(new Response(403));
        [$code] = $this->yii('indexnow/submit', ['/e']);
        self::assertSame(1, $code);
    }

    #[TestDox('indexnow/submit-record resolves records through their rules; --explain lists rule and URL; unknown class and id are reported')]
    public function testSubmitRecord(): void
    {
        (new Post(['slug' => 'one']))->save(false);
        (new Post(['slug' => 'two']))->save(false);
        (new Post(['slug' => 'draft', 'published' => false]))->save(false);
        $this->kit()->flush();
        $this->transport->posts = [];

        [$code, $output] = $this->yii('indexnow/submit-record', ['Post']);
        self::assertSame(0, $code, $output);
        self::assertStringContainsString('3 records -> 2 URL(s)', $output);
        self::assertEqualsCanonicalizing(['https://www.example.com/posts/one', 'https://www.example.com/posts/two'], $this->sentUrls());

        [$code, $output] = $this->yii('indexnow/submit-record', ['Post', '1', 'explain' => true]);
        self::assertSame(0, $code);
        self::assertStringContainsString('https://www.example.com/posts/one', $output);
        self::assertCount(1, $this->transport->posts, '--explain sends nothing');

        [$code, $output] = $this->yii('indexnow/submit-record', ['Post', '999']);
        self::assertSame(2, $code);
        self::assertStringContainsString('not found', $output);
        [$code] = $this->yii('indexnow/submit-record', ['Nope']);
        self::assertSame(2, $code);
        [$code, $output] = $this->yii('indexnow/submit-record', ['Post', 'event' => 'moved']);
        self::assertSame(2, $code);
        self::assertStringContainsString('--event must be', $output);
    }

    #[TestDox('indexnow/explain walks rule, when, URL, key and debounce for one record and sends nothing')]
    public function testExplain(): void
    {
        $post = new Post(['slug' => 'why', 'published' => false]);
        $post->save(false);
        $this->kit()->flush();

        [$code, $output] = $this->yii('indexnow/explain', ['Post', (string) $post->id]);
        self::assertSame(0, $code, $output);
        self::assertStringContainsString('Rule "post/view" (route post/view)', $output);
        self::assertMatchesRegularExpression('/when: published \((false|0)\) -> false/', $output, 'the value the condition read is shown');
        self::assertStringContainsString('No URL would be submitted', $output);

        $post->published = true;
        $post->save(false);
        $this->kit()->flush();
        [$code, $output] = $this->yii('indexnow/explain', ['Post', (string) $post->id]);
        self::assertSame(0, $code);
        self::assertStringContainsString('https://www.example.com/posts/why', $output);
        self::assertStringContainsString('host www.example.com, key abcd', $output);
        self::assertStringContainsString('php yii indexnow/submit-record', $output);
        self::assertCount(1, $this->transport->posts);

        [$code] = $this->yii('indexnow/explain', ['Post', '999']);
        self::assertSame(2, $code);
    }

    #[TestDox('indexnow/sitemap reads a local sitemap, submits in batches and prints a summary; --dry-run lists; --json is machine-readable; no argument uses <base_url>/sitemap.xml')]
    public function testSitemap(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'sitemap');
        self::assertIsString($file);
        file_put_contents($file, '<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>https://www.example.com/s1</loc><lastmod>2026-01-01</lastmod></url><url><loc>https://www.example.com/s2</loc><lastmod>2020-01-01</lastmod></url></urlset>');
        try {
            [$code, $output] = $this->yii('indexnow/sitemap', [$file, 'dry-run' => true]);
            self::assertSame(0, $code, $output);
            self::assertStringContainsString('* https://www.example.com/s1', $output);
            self::assertStringContainsString('2 URL(s) found', $output);
            self::assertSame([], $this->transport->posts);

            [$code, $output] = $this->yii('indexnow/sitemap', [$file]);
            self::assertSame(0, $code);
            self::assertSame(['https://www.example.com/s1', 'https://www.example.com/s2'], $this->sentUrls());

            [$code, $output] = $this->yii('indexnow/sitemap', [$file, 'changed-since' => '2021-01-01', 'json' => true]);
            self::assertSame(0, $code);
            self::assertStringContainsString('"url_count": 1', $output);

            [$code] = $this->yii('indexnow/sitemap', [$file, 'changed-since' => 'not a date']);
            self::assertSame(2, $code);
        } finally {
            @unlink($file);
        }

        $this->transport->onGet('https://www.example.com/sitemap.xml', new Response(200, '<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>https://www.example.com/d1</loc></url></urlset>'));
        $this->transport->posts = [];
        [$code] = $this->yii('indexnow/sitemap');
        self::assertSame(0, $code);
        self::assertSame(['https://www.example.com/d1'], $this->sentUrls());
    }

    private function stubKeyFiles(): void
    {
        $this->transport->onGet('https://www.example.com/' . self::KEY . '.txt', new Response(200, self::KEY));
        $this->transport->onGet('https://example.de/' . self::SECOND_KEY . '.txt', new Response(200, self::SECOND_KEY));
    }

    /**
     * @param array<int|string, mixed> $params positional arguments (int keys) and options (string keys)
     *
     * @return array{0: int, 1: string} exit code and output
     */
    private function yii(string $route, array $params = []): array
    {
        \assert($this->app instanceof \yii\console\Application);
        $code = $this->app->runAction($route, $params);

        return [\is_int($code) ? $code : 0, $this->output->fetch()];
    }
}
