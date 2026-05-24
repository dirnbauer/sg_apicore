# TYPO3 conformance report - after

Timestamp: 2026-05-09 19:35:28 Europe/Vienna

Changes applied:
- Backend module is available in all workspaces via `workspaces => '*'`.
- Obsolete `t3ver_move_id` field filtering was removed.
- Composer scripts now expose `composer phpstan` and `composer test`.
- Generated files are excluded through `.gitignore`.

Remaining debt:
- PHPStan max-level baseline contains 1559 existing findings, primarily legacy array/mixed typing issues.
- PHPUnit reports existing deprecations/notices under PHPUnit 12.
