# Security audit report - before

Timestamp: 2026-05-09 19:35:28 Europe/Vienna

Findings:
- No repository-local dependency patch is required for `sg_apicore`; the `ddev-demo-setup-visual-editor` patch setup targets `friendsoftypo3/content-blocks`.
- No secrets were found in the inspected metadata/config files.
- Static analysis was not configured, so max-level type/security-adjacent checks cannot currently run from the repo.

Planned changes:
- Do not add `cweagans/composer-patches` to this extension.
- Add PHPStan max-level configuration.
- Run Composer validation and available analysis after installing dependencies.
