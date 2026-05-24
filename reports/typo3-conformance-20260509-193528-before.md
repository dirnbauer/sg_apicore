# TYPO3 conformance report - before

Timestamp: 2026-05-09 19:35:28 Europe/Vienna

Findings:
- Extension metadata is not synchronized with the requested v14 extension release line.
- The backend module is configured for live workspace only.
- Obsolete workspace field `t3ver_move_id` is still treated as an internal field even though TYPO3 v14 no longer uses it.
- Quality tooling is incomplete: no PHPStan config and no Composer scripts for static analysis.

Planned changes:
- Synchronize Composer and `ext_emconf.php` metadata.
- Remove obsolete v14 workspace field references.
- Add max-level PHPStan tooling and scripts.
