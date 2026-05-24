# TYPO3 testing report - after

Timestamp: 2026-05-09 19:35:28 Europe/Vienna

Changes applied:
- Added dev dependencies for TYPO3 testing framework, PHPUnit, PHPStan, PHPStan PHPUnit, and PSR container analysis.
- Fixed PHPUnit bootstrap path for standalone repo execution.
- Fixed the hybrid router test fixture so its `apiId` matches the asserted routing behavior.

Verification:
- `composer test` passes: 150 tests, 611 assertions.
- PHPUnit still reports 50 deprecations and 43 notices.
