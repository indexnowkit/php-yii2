<?php

declare(strict_types=1);

namespace IndexNowKit\Yii2\Check;

use IndexNowKit\Check\CheckInterface;
use IndexNowKit\Check\CheckReport;
use IndexNowKit\Yii2\App;
use IndexNowKit\Yii2\IndexNowComponent;
use yii\web\UrlManager;
use yii\web\UrlRule;

/**
 * The key file route needs pretty URLs: `?r=indexnow-key-file/index&key=...` is not where the engines look.
 */
final class UrlManagerCheck implements CheckInterface
{
    /**
     * @param array<string, mixed> $options the component's options
     */
    public function __construct(private readonly array $options) {}

    public function check(CheckReport $report): void
    {
        $keyFile = \is_array($this->options['key_file'] ?? null) ? $this->options['key_file'] : [];
        $enabled = \is_bool($this->options['serve_key_file'] ?? null) ? $this->options['serve_key_file'] : (bool) ($keyFile['enabled'] ?? true);
        if (!$enabled) {
            $report->ok('key file: not served by this application (key_file.enabled: false); serve /<key>.txt yourself');

            return;
        }
        $manager = App::current()->getUrlManager();
        if (!$manager instanceof UrlManager) {
            return;
        }
        if (!$manager->enablePrettyUrl) {
            $report->error('key file: urlManager.enablePrettyUrl is off, /<key>.txt cannot be routed. Enable pretty URLs (and the web server rewrite), or serve the key file as a static file and set key_file.enabled: false.');

            return;
        }
        if (App::isConsole()) {
            $report->ok('key file: served by the web application at /<key>.txt (pretty URL rule)');

            return;
        }
        foreach ($manager->rules as $rule) {
            if ($rule instanceof UrlRule && $rule->route === IndexNowComponent::KEY_FILE_ROUTE) {
                $report->ok(\sprintf('key file: served at /%s (route %s)', str_replace(['<key:[A-Za-z0-9-]{8,128}>', '<key>'], '<key>', (string) $rule->name), $rule->route));

                return;
            }
        }
        $report->error('key file: the URL rule is missing; is the indexnow component listed in "bootstrap"?');
    }
}
