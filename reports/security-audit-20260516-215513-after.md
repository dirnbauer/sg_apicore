# Security audit report - after

Timestamp: 2026-05-16 21:55:13 Europe/Vienna

Changes made:

- Verified dependency advisory state with Composer audit.
- Preserved PHPStan exclusion for vendored public assets.
- Added a minimal-permission GitHub Actions workflow with `contents: read`.

Verification:

- `composer audit --format=plain`: no advisories.
- `Build/Scripts/runTests.sh -s ci -p 8.3`: passed.
- `.github/workflows/ci.yml`: parsed successfully.

Residual notes:

- `gitleaks`, `trivy`, and Semgrep are not configured in this repository.
  Adding them to a future GitHub Actions workflow would strengthen supply-chain
  and secret scanning.
