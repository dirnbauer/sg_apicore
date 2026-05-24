# Security audit report - after

Timestamp: 2026-05-09 19:35:28 Europe/Vienna

Changes applied:
- Checked current and historical `ddev-demo-setup-visual-editor` Composer patch setup.
- Historical `sgalinski/sg-apicore` patches are already represented in this extension:
  `sg-apicore-fluid14-viewhelper-signature.patch`,
  `sg-apicore-path-analysis-guard-typo3-request.patch`,
  `sg-apicore-datahandler-admin-v14.patch`,
  `sg-apicore-resource-include-disabled.patch`,
  `sg-apicore-route-cache-resource-signature.patch`, and
  `sg-apicore-backend-user-file-fields.patch`.
- Did not add `cweagans/composer-patches` to this extension because no vendor patch needs to be applied from this package.
- Composer install completed with no security vulnerability advisories.
- PHPStan max-level tooling is installed and runnable.

Verification:
- `composer phpstan` passes at max level with baseline.
- `composer test` passes.
