# TYPO3 extension upgrade report - before

Timestamp: 2026-05-16 21:55:13 Europe/Vienna

Scope: Upgrade `sg_apicore` to TYPO3 14 only and drop TYPO3 13 support.

Findings:

- `composer.json` already targets package version `14.0.0`, PHP `^8.3`, TYPO3
  core `^14.3`, and `typo3/cms-workspaces` `^14.3`.
- `ext_emconf.php` already targets extension version `14.0.0`, TYPO3
  `14.3.0-14.9.99`, and PHP `8.3.0-8.5.99`.
- No GitHub Actions workflow exists in this checkout, so local CI is the
  executable gate.
- The local test runner existed but was not executable.
- `vendor/` was missing, so quality gates could not run before dependency
  installation.

Suggested changes:

- Keep the v14-only constraints and verify them with current dependencies.
- Make `Build/Scripts/runTests.sh` executable.
- Run the full local CI gate after installing dependencies.
