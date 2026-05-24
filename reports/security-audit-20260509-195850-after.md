# Security Audit Report - 2026-05-09 19:58:50

## Scope

- Applied existing skill: `craft-workspace-webconsulting-skills:security-audit`
- Extension: `sgalinski/sg-apicore`
- Target: TYPO3 v14-only extension state after the v14/workspaces cleanup

## Tooling

- `composer audit --format=plain`: passed, no known vulnerable package advisories found.
- `composer phpstan`: passed at `level: max`.
- `composer test`: passed with 152 tests and 613 assertions.
- `gitleaks`, `trivy`, `semgrep`, and `opengrep` were not installed in the local environment, so the audit used Composer audit plus targeted static inspection.

## Findings Fixed

### Backend token mutations used GET links

The backend token list previously exposed token regeneration and revocation as GET links. TYPO3 backend routes include route tokens, but these actions mutate secrets and should still be submitted through POST with an action-specific form token.

Changes:

- `Classes/Controller/Backend/ApiCoreController.php`
  - Injected TYPO3 `FormProtectionFactory`.
  - Generated a module-specific token for token management.
  - Validated the token before create, revoke, and regenerate actions.
  - Rejected invalid token submissions with an error flash message and redirect.
- `Resources/Private/Templates/Backend/ApiCore/Tokens.html`
  - Converted revoke/regenerate controls from GET links to POST forms.
  - Added the form-protection token to create, revoke, and regenerate submissions.

### JWT base64 decoding accepted malformed input

`JwtService` decoded JWT segments with non-strict base64 handling. Malformed JWT segments could flow into JSON decoding instead of being rejected early.

Changes:

- `Classes/Service/JwtService.php`
  - Validates URL-safe base64 characters before decode.
  - Uses strict `base64_decode(..., true)`.
  - Returns `null` from JWT decode when any segment is malformed.
- `tests/Unit/Service/JwtServiceTest.php`
  - Added regression coverage for malformed JWT segments.
  - Added a valid encode/decode round-trip with expected-claim checks.

## Verification

- `php -l Classes/Controller/Backend/ApiCoreController.php`: passed.
- `php -l Classes/Service/JwtService.php`: passed.
- `php -l tests/Unit/Service/JwtServiceTest.php`: passed.
- `composer phpstan`: passed at level max.
- `composer test`: passed.
- `composer audit --format=plain`: passed.

## Residual Risk

- PHPUnit still reports existing deprecations and notices. These are not introduced by the security changes but should be cleaned up in a separate testing hardening pass.
- Optional secret-scanning/container-scanning tools are not installed locally. If CI provides `gitleaks`, `trivy`, or `semgrep`, run them there for additional coverage.
