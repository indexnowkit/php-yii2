<?php

declare(strict_types=1);

namespace IndexNowKit\Yii2\Check;

use IndexNowKit\Attribute\RuleRegistry;
use IndexNowKit\Check\CheckInterface;
use IndexNowKit\Check\CheckReport;
use Throwable;

/**
 * What `locales: 'all'` expands to. The option lives in the adapter (`router.locales`), the rule that asks for it
 * lives on the model: with an empty `router.locales` the bridge falls back to one URL in the current locale, so a
 * rule written for a multi-locale site silently announces a single page. The rules of the classes the component
 * knows by name (`active_record.models`) are read here; a class hooked through `IndexNowBehavior` is not known
 * before it is loaded, which is why the ok line names the option too.
 */
final class RouterCheck implements CheckInterface
{
    public const CODE = 'router.locales';

    /**
     * @param list<string>       $locales the `router.locales` option
     * @param list<class-string> $models  the classes of `active_record.models`, whose rules can be read now
     */
    public function __construct(private readonly array $locales, private readonly array $models, private readonly RuleRegistry $rules) {}

    public function check(CheckReport $report): void
    {
        if ($this->locales !== []) {
            $report->ok(\sprintf('router: locales: \'all\' expands to %s (router.locales)', implode(', ', $this->locales)), self::CODE);

            return;
        }
        if ($this->modelAsksForAllLocales()) {
            $report->warning('router: a rule asks for locales: \'all\' but router.locales is empty, so it yields one URL in the current locale; list the locales of the site in router.locales', self::CODE);

            return;
        }
        $report->ok('router: router.locales is empty; a rule with locales: \'all\' yields one URL in the current locale', self::CODE);
    }

    /** Whether a class the component hooks by name carries a rule with `locales: 'all'`. */
    private function modelAsksForAllLocales(): bool
    {
        foreach ($this->models as $class) {
            try {
                $rules = $this->rules->rules($class);
            } catch (Throwable) {
                continue; // a broken rule is the business of the core's own check line
            }
            foreach ($rules->rules as $rule) {
                if ($rule->locales === 'all') {
                    return true;
                }
            }
        }

        return false;
    }
}
