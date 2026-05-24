# TYPO3 security report - after

Timestamp: 2026-05-09 19:35:28 Europe/Vienna

Changes applied:
- Auto-CRUD writes continue to use TYPO3 `DataHandler`.
- Existing backend-user workspace is preserved.
- Dedicated write backend users now initialize their TYPO3 workspace through `workspaceInit()`.
- Optional `apiResourceWriteWorkspaceId` uses TYPO3 `BackendUserAuthentication::setWorkspace()`.
- README and resource docs recommend a dedicated backend user for production write APIs.

Residual risk:
- Admin bypass remains available when `apiResourceWriteBackendUserId` is `0`; documentation now calls out that it should be avoided for public or partner-facing write APIs.
