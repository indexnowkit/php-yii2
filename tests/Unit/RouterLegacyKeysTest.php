<?php

declare(strict_types=1);

namespace IndexNowKit\Yii2\Tests\Unit;

use IndexNowKit\Yii2\Tests\Yii2TestCase;
use IndexNowKit\Yii2\Wiring;
use PHPUnit\Framework\Attributes\TestDox;

/** The pre-0.12 spellings of the `router` block (`languages`, `language_parameter`, `set_app_language`). */
final class RouterLegacyKeysTest extends Yii2TestCase
{
    #[TestDox('router.languages / language_parameter / set_app_language are still read, each with a deprecation warning naming the new key')]
    public function testLegacyKeysAreReadAndWarned(): void
    {
        $resolver = $this->component()->routeResolver();
        self::assertSame(['fr', 'it'], $resolver->locales('all'), 'router.languages is read as router.locales');
        self::assertSame('https://www.example.com/article/view?slug=ciao&lang=it', $resolver->generate('article/view', ['slug' => 'ciao'], 'it'), 'router.language_parameter is read as router.locale_parameter (the URL rule declares `language`, not `lang`, so the query form)');

        $warnings = $this->logger->messages('warning');
        $deprecations = array_values(array_filter($warnings, static fn(string $m): bool => str_contains($m, 'deprecated')));
        self::assertCount(3, $deprecations, implode("\n", $warnings));
        foreach (Wiring::ROUTER_RENAMED as $old => $new) {
            self::assertTrue((bool) array_filter($deprecations, static fn(string $m): bool => str_contains($m, '"router.' . $old . '"') && str_contains($m, '"router.' . $new . '"')), $old);
        }
    }

    protected function optionOverrides(): array
    {
        // the fixture sets router.locales; the old key is read only when the new one is absent
        return ['router' => ['locales' => null, 'languages' => ['fr', 'it'], 'language_parameter' => 'lang', 'set_app_language' => false]];
    }
}
