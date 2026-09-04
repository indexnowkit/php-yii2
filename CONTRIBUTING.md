# Contributing

This repository is a read-only split of [`indexnowkit/php`](https://github.com/indexnowkit/php) (`packages/yii2`).
Please open issues and pull requests there; releases are tagged in the monorepo as `yii2@x.y.z` and mirrored here.

Quick rules (details in the monorepo's CONTRIBUTING.md):

- Every option gets a line in `docs/configuration.md` and a test (`tests/Feature/`, option overrides through
  `Yii2TestCase::optionOverrides()`).
- Nothing may throw from an ActiveRecord event, the response or the queue job into the application: log under the
  IndexNow category instead.
- The core conformance kits (`tests/Conformance/`) must pass unchanged; verify-on-commit stays query-per-subject.
- phpstan level 9 and php-cs-fixer must pass. PHPUnit, not Codeception.
