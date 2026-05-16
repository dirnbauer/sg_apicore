# Security audit report - before

Timestamp: 2026-05-16 21:55:13 Europe/Vienna

Findings:

- Dependency audit was blocked until Composer install completed.
- No GitHub Actions workflow exists locally to inspect for unsafe `run:`
  interpolation.
- Bundled Swagger UI assets are present under `Resources/Public/Vendor/`.
- Source grep did not identify command execution or unsafe deserialization in
  extension PHP code.

Suggested changes:

- Run Composer audit.
- Keep generated/vendor frontend assets excluded from PHPStan source analysis.
- If GitHub Actions are added later, include Composer install, PHP lint,
  PHPStan max, PHPUnit, and Composer audit.
