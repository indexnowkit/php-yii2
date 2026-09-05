<?php

declare(strict_types=1);

namespace IndexNowKit\Yii2\Tests\Feature;

use IndexNowKit\Testing\Conformance\KeyFileAssertions;
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

        KeyFileAssertions::assertKeyFileResponse($response->statusCode, self::headers($response), (string) $response->content, self::KEY, expectVaryHost: true);
    }

    #[TestDox('H01b the key file of another configured host is served only on that host')]
    public function testKeyFileIsPerHost(): void
    {
        $this->expectNotFound(self::SECOND_KEY . '.txt');
        $response = $this->request(self::SECOND_KEY . '.txt', 'https://example.de');
        KeyFileAssertions::assertKeyFileResponse($response->statusCode, self::headers($response), (string) $response->content, self::SECOND_KEY, expectVaryHost: true);
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

    /** Yii answers a missing route with an exception; the status code is what the browser would see. */
    private function expectNotFound(string $path, string $hostInfo = self::BASE_URL): void
    {
        try {
            $this->request($path, $hostInfo);
            self::fail('expected a 404 for ' . $path);
        } catch (NotFoundHttpException $e) {
            KeyFileAssertions::assertNotServed($e->statusCode);
        }
    }

    /**
     * @return array<string, list<string>>
     */
    private static function headers(Response $response): array
    {
        $headers = [];
        foreach ($response->getHeaders() as $name => $values) {
            $headers[(string) $name] = array_values(array_map(strval(...), (array) $values));
        }

        return $headers;
    }
}
