# TYPO3 testing report - after

Timestamp: 2026-05-16 21:55:13 Europe/Vienna

Changes made:

- Made `Build/Scripts/runTests.sh` executable.
- Cleaned PHPUnit test doubles in affected unit tests.
- Removed stale PHPStan baseline entries.
- Added a GitHub Actions workflow that runs the local CI gate across PHP
  8.2-8.5.

Verification:

- `Build/Scripts/runTests.sh -s lint -p 8.3`: passed.
- `Build/Scripts/runTests.sh -s phpstan -p 8.3`: passed.
- `Build/Scripts/runTests.sh -s unit -p 8.3`: `OK (152 tests, 613 assertions)`.
- `Build/Scripts/runTests.sh -s ci -p 8.3`: passed.
- `.github/workflows/ci.yml`: parsed successfully.
