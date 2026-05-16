# TYPO3 testing report - before

Timestamp: 2026-05-16 21:55:13 Europe/Vienna

Findings:

- `Build/Scripts/runTests.sh` existed but was not executable.
- PHPUnit passed functionally, but emitted 50 PHPUnit deprecations and 43
  PHPUnit notices.
- PHPStan max initially failed only because the baseline contained unmatched
  obsolete ignores.

Suggested changes:

- Make the test runner executable.
- Remove obsolete PHPStan baseline entries.
- Make PHPUnit test doubles compatible with PHPUnit 13.
