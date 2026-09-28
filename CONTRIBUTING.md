# Contributing

Use a branch and pull request with a concrete reproduction or a before-and-after explanation. Keep customer data, credentials and quote exports out of issues and commits. Use invented fixtures. Report vulnerabilities privately as described in SECURITY.md.

Preserve sent revisions, their PDFs and the quote counter. Changes to pricing, acceptance, access control or storage need regression tests against a disposable WordPress and WooCommerce store. Never weaken the test database guards.

Before you propose a change, run:

1. `php tests/render.php`
2. `wp eval-file tests/integration.php` in the disposable test store
3. `python3 tests/concurrency.py <path-to-wp-cli-wrapper>`
4. `python3 tools/build.py` and `php tests/render.php --packaged`
5. `composer audit --locked`

Keep Free changes in this repository. Paid services and plugin telemetry are not part of it.
