# TYPO3 security report - before

Timestamp: 2026-05-09 19:35:28 Europe/Vienna

Findings:
- Auto-CRUD correctly uses TYPO3 `DataHandler`, but the fallback write user is an admin bypass when no dedicated backend user is configured.
- Resource writes hard-code live workspace, which bypasses expected workspace staging behavior.
- Logging redaction defaults include token and authorization keys.

Planned changes:
- Preserve TYPO3 `DataHandler` for writes.
- Stop forcing live workspace for backend-user-based writes.
- Document the dedicated backend user recommendation for production.
