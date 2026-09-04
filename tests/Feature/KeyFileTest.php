<?php

declare(strict_types=1);

namespace IndexNowKit\Yii2\Tests\Feature;

use IndexNowKit\Yii2\Tests\Yii2TestCase;
use PHPUnit\Framework\Attributes\TestDox;
use yii\web\NotFoundHttpException;
use yii\web\Response;

final class KeyFileTest extends Yii2TestCase
{
    #[TestDox('H01 GET /{key}.txt -> 200 text/plain with the key, short cache, Vary: Host with a hosts map')]
    public function testKeyFile(): void
    {
        $response = $this->request(self::KEY . '.txt');

        self::assertSame(200, $response->statusCode);
        self::assertSame(self::KEY, $response->content);
        self::assertSame('text/plain; charset=utf-8', $response->getHeaders()->get('Content-Type'));
        self::assertSame('public, max-age=300', $response->getHeaders()->get('Cache-Control'));
        self::assertSame('Host', $response->getHeaders()->get('Vary'));
    }

    #[TestDox('H01b the key file of another configured host is served only on that host')]
    public function testKeyFileIsPerHost(): void
    {
        $this->expectNotFound(self::SECOND_KEY . '.txt');
        self::assertSame(self::SECOND_KEY, $this->request(self::SECOND_KEY . '.txt', 'https://example.de')->content);
        $this->expectNotFound(self::KEY . '.txt', 'https://example.de');
    }

    #[TestDox('H02 GET /other.txt -> 404; a key shorter than 8 characters does not even match the rule')]
    public function testUnknownKey(): void
    {
        $this->expectNotFound('abcdefghijklmnop.txt');
        $this->expectNotFound('short.txt');
    }

    private function request(string $path, string $hostInfo = self::BASE_URL): Response
    {
        \assert($this->app instanceof \yii\web\Application);
        $request = $this->app->getRequest();
        $request->setHostInfo($hostInfo);
        $request->setPathInfo($path);
        $response = $this->app->handleRequest($request);
        \assert($response instanceof Response);

        return $response;
    }

    private function expectNotFound(string $path, string $hostInfo = self::BASE_URL): void
    {
        try {
            $this->request($path, $hostInfo);
            self::fail('expected a 404 for ' . $path);
        } catch (NotFoundHttpException) {
            self::assertTrue(true);
        }
    }
}
