# Security

This package is a thin adapter over `indexnowkit/core`; the security notes of the core package apply
(key handling, URL validation, HTTP limits): https://github.com/indexnowkit/php/blob/main/packages/core/SECURITY.md

Yii2-specific: the key file controller disables CSRF validation (it answers GET only), starts no session and serves
only the key of the requested host. `SubmitUrlsJob` payloads contain URLs only, never the key.

Report vulnerabilities privately via [GitHub security advisories](https://github.com/indexnowkit/php/security/advisories/new)
or to i.pinchuk.work@gmail.com. Please do not open public issues for security reports.
