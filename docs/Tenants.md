# Tenants

In `sg_apicore`, every request is executed within a **TenantContext**. This enables the operation of multiple tenants (
e.g., different websites or departments) within a single TYPO3 instance.

## Tenant Resolution

By default, the tenant is automatically derived from the **TYPO3 Site**. The `TenantResolverChain` successively checks
various strategies:

1. **SiteTenantResolver**: Uses the TYPO3 site configuration.
2. **HeaderTenantResolver**: Checks for the `X-Tenant-Id` HTTP header.

### Configuration

The strategy of the `SiteTenantResolver` can be adjusted in the extension configuration (`settings.php` or Extension
Manager):

* `siteTenantIdSource`:
    * `identifier` (default): Uses the site identifier from TYPO3 (e.g., `main_site`).
    * `baseHost`: Uses the site's hostname (e.g., `www.example.com`).
    * `rootPageId`: Uses the UID of the root page.
* `onMissingTenant`: HTTP status code when no tenant is found (default: `404`).

## Usage in Code

The `TenantContext` is available in the request object as an attribute:

```php
use SGalinski\SgApiCore\Context\TenantContext;

public function myAction(ServerRequestInterface $request): ResponseInterface {
    /** @var TenantContext $tenantContext */
    $tenantContext = $request->getAttribute('api.tenant');
    $tenantId = $tenantContext->getTenantId();

    // Access the TYPO3 site object (if available)
    $site = $tenantContext->getSite();
    // ...
}
```

## Multi-Language Handling

The `TenantContext` also captures the current language. By default, `sg_apicore` is fully language-aware:

1. **Language Resolution**: The language is automatically detected via the TYPO3 Site configuration (e.g., via URL
   prefixes like `/en/api/...`).
2. **Context Initialization**: The extension automatically initializes the TYPO3 `LanguageAspect` in the global
   `Context`. This ensures that Repositories and the `TcaMapper` automatically return translated content.
3. **Usage**:
   ```php
   $languageId = $tenantContext->getLanguageId();
   ```

## Custom Resolvers

You can implement your own resolvers by implementing the `TenantResolverInterface` and registering the service with the
`sg_apicore.tenant_resolver` tag.
