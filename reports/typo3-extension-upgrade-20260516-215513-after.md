# TYPO3 extension upgrade report - after

Timestamp: 2026-05-16 21:55:13 Europe/Vienna

Changes made:

- Preserved the v14-only Composer and TER metadata.
- Installed dependencies from `composer.lock`; TYPO3 packages resolved to
  v14.3.0 locally.
- Made `Build/Scripts/runTests.sh` executable.
- Added a GitHub Actions workflow for PHP 8.2, 8.3, 8.4, and 8.5.

Verification:

- `Build/Scripts/runTests.sh -s ci -p 8.3` passed.
- `composer audit --format=plain` reported no advisories.
- `.github/workflows/ci.yml` parses as valid YAML.
