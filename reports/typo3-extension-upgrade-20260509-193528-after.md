# TYPO3 extension upgrade report - after

Timestamp: 2026-05-09 19:35:28 Europe/Vienna

Changes applied:
- Set extension package version to `14.0.0` in `composer.json` and `ext_emconf.php`.
- Kept TYPO3 support v14-only with `typo3/cms-core:^14.3`.
- Added explicit PHP `^8.2` Composer constraint and `8.2.0-8.5.99` EM constraint.
- Added `typo3/cms-workspaces:^14.3`.
- Removed the v12 TypoScript initialization branch and v13-specific naming from the runtime path.
- Checked historical `ddev-demo-setup-visual-editor` `sgalinski/sg-apicore` Composer patches; no Composer patch setup needs to be re-added because the patch contents are in this repository.

Verification:
- `composer test` passes: 150 tests, 611 assertions.
- `composer phpstan` passes at `level: max` with the generated baseline.
