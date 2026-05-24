# TYPO3 Extension Upgrade Report - 2026-05-09 20:06:15

## Scope

- Applied existing skill: `craft-workspace-webconsulting-skills:typo3-extension-upgrade`
- Target: TYPO3 v14-only, no v13 compatibility branch
- Focus: v13 -> v14 removed APIs and post-upgrade verification on the current working tree

## Changes Applied

### Removed remaining TSFE usage

- Reworked `Classes/Service/ApiTypoScriptSetupService.php` to stop creating or mutating `TypoScriptFrontendController`.
- Uses TYPO3 v14 request attributes and core APIs instead:
  - `PageInformationFactory`
  - `FrontendTypoScriptFactory`
  - `frontend.page.information`
  - `frontend.typoscript`
- Creates a synthetic `PageArguments` for API requests that run before normal frontend routing, based on the resolved tenant site root page id.
- Keeps a minimal fallback `FrontendTypoScript` setup for API contexts where the full TYPO3 frontend TypoScript pipeline cannot be initialized.

### Removed TCA `ctrl.searchFields`

- Removed `ctrl.searchFields` from `Configuration/TCA/tx_apicore_token.php`.
- Added `searchable => false` to fields that were not part of the previous search field list:
  - `scopes`
  - `expires_at`
  - `revoked_at`
  - `last_used_at`

### Removed TSFE dependency from RTE mapping

- Updated `Classes/Mapper/TcaMapper.php` to read parseFunc configuration from the request `frontend.typoscript` attribute.
- Removed mutation of `$GLOBALS['TSFE']->tmpl->setup`.
- Added local parseFunc fallback configuration for API contexts without full TypoScript.
- Updated `tests/Unit/Mapper/TcaMapperTest.php` to assert v14 request-attribute behavior.

### PHPStan baseline refreshed

- Regenerated `Build/phpstan/phpstan-baseline.neon` after deleting stale TSFE-related findings.
- Normal `composer phpstan` now passes at level max against the current v14 code.

## Upgrade Scanner Checks

Targeted search for common v14 removals returned no matches in extension PHP/config/private templates/docs:

- `TypoScriptFrontendController`, `TSFE`, `frontend.controller`
- Fluid `renderStatic`, removed standalone/template views
- Extbase annotation namespace
- Extbase `HashService` / `GeneralUtility::hmac`
- TCA `subtype_value_field`, `subtypes_addlist`, `ctrl.searchFields`, `eval=year`, `pages.url`
- `tt_content.list_type` / old `addPlugin()` usage
- removed JS/CSS compression TypoScript flags
- `GeneralUtility::_GET/_POST/_GP`
- `PDO::PARAM_*`
- `Environment::getComposerRootPath`

## Tooling Notes

- Rector, TYPO3 Rector, Fractor, and php-cs-fixer are not currently installed in this extension's Composer dependencies, so no Rector/Fractor/php-cs-fixer dry run was executed.
- The extension already has TYPO3 v14-only Composer constraints and PHPStan max-level verification from the earlier upgrade pass.

## Verification

- `php -l Classes/Service/ApiTypoScriptSetupService.php`: passed.
- `php -l Configuration/TCA/tx_apicore_token.php`: passed.
- `php -l Classes/Mapper/TcaMapper.php`: passed.
- v14 removed-API search: passed for extension source/config/private templates/docs.
- `composer phpstan`: passed at level max.
- `composer test`: passed with 152 tests and 613 assertions.
- `composer audit --format=plain`: passed, no advisories found.
- `composer validate --strict`: Composer returns warning-only non-zero because `composer.json` contains the explicit `version` field requested for this extension.

## Remaining Work

- PHPUnit still reports existing deprecations and notices. They are not introduced by this pass but should be addressed separately.
- Real TYPO3 v14 backend/API smoke testing is still required in a running instance to validate full TypoScript endpoint behavior.
