<?php

declare(strict_types=1);

namespace IndexNowKit\Yii2\Tests\Feature;

use IndexNowKit\Console\Definitions;
use IndexNowKit\Sitemap\Console\Definitions as SitemapDefinitions;
use IndexNowKit\Yii2\Console\IndexNowController;
use IndexNowKit\Yii2\Tests\Yii2TestCase;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * `php yii help indexnow/<action>` describes the options with the texts of `Console\Definitions`, the same the
 * bundle and artisan print, instead of the docblocks of the controller's properties.
 */
final class IndexNowControllerHelpTest extends Yii2TestCase
{
    protected function console(): bool
    {
        return true;
    }

    #[TestDox('the option help of submit and check carries the descriptions and defaults of Console\Definitions')]
    public function testOptionHelpComesFromTheDefinitions(): void
    {
        $submit = $this->optionsHelp('submit');
        foreach (['force', 'dry-run', 'json'] as $name) {
            self::assertSame(Definitions::submit()->option($name)->description, $submit[$name]['comment']);
            self::assertFalse($submit[$name]['default']);
            self::assertSame('boolean, 0 or 1', $submit[$name]['type']);
        }

        $check = $this->optionsHelp('check');
        foreach (['live', 'host', 'probe-url', 'json', 'strict'] as $name) {
            self::assertSame(Definitions::check()->option($name)->description, $check[$name]['comment']);
        }
        self::assertArrayNotHasKey('verbose', $check, 'the own --verbose is gone: -v/-vv/-vvv as in symfony/console');
        self::assertStringStartsWith(' verbose output (-v), as in symfony/console', $check['v']['comment'], 'v is not in the definitions: the property docblock applies');
        self::assertArrayHasKey('vvv', $check);
        self::assertSame('string[]', $check['host']['type'], 'a repeatable option is an array property, --host=a,b');

        $submitRecord = $this->optionsHelp('submit-record');
        self::assertSame(1000, $submitRecord['limit']['default'], 'the default of the definition, as the int the property holds');
        self::assertSame('updated', $submitRecord['event']['default']);

        $keyGenerate = $this->optionsHelp('key-generate');
        self::assertSame(Definitions::keyGenerate()->option('write-env')->description, $keyGenerate['write-env']['comment']);
        self::assertSame(32, $keyGenerate['length']['default']);
    }

    #[TestDox('sitemap: the descriptions of Sitemap\Console\Definitions with the package, the property docblocks without it')]
    public function testSitemapHelpWithAndWithoutThePackage(): void
    {
        $withPackage = $this->optionsHelp('sitemap');
        self::assertSame(SitemapDefinitions::sitemap()->option('changed-since')->description, $withPackage['changed-since']['comment']);

        $this->component()->sitemapInstalled = false;
        $withoutPackage = $this->optionsHelp('sitemap');
        self::assertSame(array_keys($withPackage), array_keys($withoutPackage), 'the same options are accepted');
        self::assertSame('', $withoutPackage['changed-since']['comment']);
    }

    /**
     * @return array<string, array{type: ?string, default: mixed, comment: string}>
     */
    private function optionsHelp(string $actionId): array
    {
        $controller = new IndexNowController('indexnow', $this->app);
        $action = $controller->createAction($actionId);
        self::assertNotNull($action);

        return $controller->getActionOptionsHelp($action);
    }
}
