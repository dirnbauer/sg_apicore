# TYPO3 testing report - before

Timestamp: 2026-05-09 19:35:28 Europe/Vienna

Findings:
- Unit tests exist under `tests/Unit`.
- `composer.json` has no dev dependencies or test/PHPStan scripts.
- `phpunit.xml` exists, but dependency installation is not currently represented in Composer.

Planned changes:
- Add TYPO3 testing framework, PHPUnit, and PHPStan dev dependencies.
- Add Composer scripts for `test` and `phpstan`.
- Run tests and static analysis after dependency installation.
