# TYPO3 conformance report

Timestamp: 2026-05-09 19:51:00 Europe/Vienna

Skill used: `/Users/dirnbauer/projects/webconsulting-skills/skills/typo3-conformance/SKILL.md`

Scope:
- TYPO3 extension `sgalinski/sg-apicore`
- Target: TYPO3 v14.3+
- Version: `14.0.0`

Checks performed:
- Read `composer.json` and `ext_emconf.php`.
- Checked strict type declarations in `Classes/**/*.php`.
- Checked `$GLOBALS`, `GeneralUtility::makeInstance()`, `HashService`,
  magic repository finder patterns, Bootstrap 4 data attributes, and
  unmatched PHPStan ignore markers.
- Checked quality-tool presence: `Build/`, `composer.lock`,
  `phpstan.neon.dist`.
- Checked TCA search fields and backend template link attributes.

Score:
- Architecture: 15/20
- Coding guidelines: 8/20
- PHP quality: 12/20
- Testing: 12/20
- Practices: 10/20
- Base score: 57/100

Passing items:
- Extension metadata is TYPO3 v14-only.
- `typo3/cms-workspaces:^14.3` is required.
- `composer.json` and `ext_emconf.php` versions are synchronized at `14.0.0`.
- PHPStan is configured at `level: max`.
- `composer phpstan` passes with a tracked baseline.
- `composer test` passes.
- No `HashService`, `GeneralUtility::hmac()`, Bootstrap 4 data attributes, or
  magic Extbase repository finder patterns were found in extension classes.
- TCA has `searchFields`.
- Backend links using `target="_blank"` include `rel="noopener noreferrer"`
  where checked.

Findings:
- `Classes/**/*.php` currently have no `declare(strict_types=1)` headers.
- Existing code still relies heavily on `$GLOBALS`, especially for TCA, TSFE,
  TYPO3 request, language service, and backend user state.
- Existing code still uses `GeneralUtility::makeInstance()` for runtime object
  creation and service fallbacks.
- PHPStan max-level conformance is transitional because
  `Build/phpstan/phpstan-baseline.neon` contains existing legacy type debt.
- No `Build/Scripts/runTests.sh` exists.
- No CI workflow was found in this checkout.
- PHPUnit passes but reports existing deprecations and notices.
- `ext_tables.php` still exists. TYPO3 v14.3 deprecates extension-level
  `ext_tables.php`; the file should be removed or migrated if still loaded.

Recommended next changes:
- Add `declare(strict_types=1)` to extension PHP files in a mechanical pass.
- Migrate `ext_tables.php` responsibilities or remove it if it is empty/obsolete.
- Add `Build/Scripts/runTests.sh` that wraps `composer test` and
  `composer phpstan`.
- Reduce the PHPStan baseline in focused passes.
- Replace service-locator fallbacks with constructor DI where practical.

Verification state:
- Last `composer phpstan`: passed.
- Last `composer test`: passed, 150 tests / 611 assertions, with existing
  PHPUnit deprecations/notices.
