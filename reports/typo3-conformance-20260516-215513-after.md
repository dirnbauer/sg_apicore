# TYPO3 conformance report - after

Timestamp: 2026-05-16 21:55:13 Europe/Vienna

Changes made:

- Removed unmatched PHPStan baseline entries for obsolete Icon API errors.
- Updated affected unit tests to use explicit mocks where argument constraints
  are configured.
- Added PHPUnit's explicit allowance attribute on test classes that intentionally
  use mock objects as loose doubles.

Verification:

- PHPStan max: passed with no errors.
- PHPUnit: `OK (152 tests, 613 assertions)` with no deprecations or notices.
- Local CI: passed.
