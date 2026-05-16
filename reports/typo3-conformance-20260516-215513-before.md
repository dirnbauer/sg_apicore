# TYPO3 conformance report - before

Timestamp: 2026-05-16 21:55:13 Europe/Vienna

Findings:

- Extension metadata is v14-only and aligns with TYPO3 14.3 LTS.
- PHPStan was configured at `level: max`.
- The baseline contained stale TYPO3 Icon API suppressions for errors that no
  longer exist.
- PHPUnit produced 50 deprecations and 43 notices, mostly from PHPUnit 13 test
  double compatibility checks.
- `composer validate --strict` is valid but exits with a warning because the
  user-requested `version` field is present.

Suggested changes:

- Remove stale PHPStan baseline entries.
- Clean PHPUnit test doubles so the suite is fully green.
- Keep `version` because the upgrade request explicitly requires it.
