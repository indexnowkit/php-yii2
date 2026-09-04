<?php

declare(strict_types=1);

namespace IndexNowKit\Yii2\Http;

use IndexNowKit\Key\KeyFileResponder;
use IndexNowKit\Yii2\App;
use yii\web\Controller;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * GET /<key>.txt -> the key itself, only for a key of the requested host (404 otherwise). Registered by the
 * component under the `indexnow-key-file` controller id with a pretty URL rule; no session, no CSRF.
 */
final class KeyFileController extends Controller
{
    public const DEFAULT_PATTERN = '<key:[A-Za-z0-9-]{8,128}>.txt';

    public $enableCsrfValidation = false;

    /** Component id of {@see IndexNowComponent}. */
    public string $component = 'indexnow';

    public function actionIndex(string $key): Response
    {
        $component = App::indexNow($this->component);
        $app = App::web();
        if ($component === null || $app === null) {
            throw new NotFoundHttpException();
        }
        $host = (string) parse_url((string) $app->getRequest()->getHostInfo(), PHP_URL_HOST);
        $body = $component->keyFileResponder()->bodyForKey($key, $host);
        if ($body === null) {
            throw new NotFoundHttpException();
        }
        $keyFile = $component->block('key_file');
        $maxAge = is_numeric($keyFile['cache_max_age'] ?? null) ? (int) $keyFile['cache_max_age'] : KeyFileResponder::DEFAULT_MAX_AGE;
        $response = $app->getResponse();
        $response->format = Response::FORMAT_RAW;
        $response->content = $body;
        foreach (KeyFileResponder::headers($maxAge, $component->config()->hosts !== []) as $name => $value) {
            $response->getHeaders()->set($name, $value);
        }

        return $response;
    }
}
