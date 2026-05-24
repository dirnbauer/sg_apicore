# TYPO3 extension upgrade report - before

Timestamp: 2026-05-09 19:35:28 Europe/Vienna

Scope: Upgrade `sg_apicore` to TYPO3 v14-only and remove TYPO3 13 compatibility concerns.

Findings:
- `composer.json` and `ext_emconf.php` already require TYPO3 14.3+, but the extension package version is still `1.20.0`.
- `composer.json` has no explicit PHP constraint and no TYPO3 workspaces dependency.
- No PHPStan configuration exists in the repository.
- `Configuration/Backend/Modules.php` restricts the backend module to the live workspace.

Planned changes:
- Set extension version to `14.0.0`.
- Keep TYPO3 constraint v14-only and add explicit PHP/TYPO3 workspace dependencies.
- Add TYPO3 v14.3-inspired PHPStan configuration at max level.
- Update backend module/workspace handling.
