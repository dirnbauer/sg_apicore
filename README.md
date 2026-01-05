# Ext: sg_apicore

<img src="https://www.sgalinski.de/typo3conf/ext/project_theme/Resources/Public/Images/logo.svg" alt=""/>

License: [GNU GPL, Version 2](https://www.gnu.org/licenses/gpl-2.0.html)

Repository: https://gitlab.sgalinski.de/typo3/sg_apicore

Please report bugs here: https://gitlab.sgalinski.de/typo3/sg_apicore/-/issues

## Short Summary

Provides an API framework for TYPO3: Multi-API, Mulit-Tenants, Attribute based endpoint configuration, Logging,
Token JWT Bearer auth, User auth, Entity CRUD registration, Custom Endpoints

## Directory Structure

The extension follows a standard TYPO3 extension structure with a focus on clean separation of concerns:

- `Classes/`
    - `Attribute/`: PHP attributes for routing, configuration, and security (e.g., `#[ApiRoute]`, `#[RequireScopes]`).
    - `Configuration/`: Configuration readers and objects.
    - `Context/`: Value objects for request context (e.g., `TenantContext`).
    - `Controller/`: API controllers handling the requests.
    - `Domain/`:
        - `Repository/`: Repositories for database access (e.g., `TokenRepository`).
    - `Middleware/`: PSR-15 middlewares (e.g., `ApiRequestMiddleware` for request interception).
    - `Security/`: Authentication and authorization logic (e.g., `BearerTokenProvider`, `AuthContext`).
    - `Service/`
        - `Tenant/`: Tenant resolution logic and resolvers.
        - `ApiRegistry.php`: Service to register APIs and versions.
        - `Router.php`: FastRoute-based dispatcher.
- `Configuration/`: TYPO3 configuration files (Services, Middlewares, TCA).
- `tests/`: Unit and functional tests.

## Installation

1. Install the extension via composer:
   ```bash
   composer require sgalinski/sg-apicore
   ```

2. Activate the extension in the TYPO3 Extension Manager.

## Testing

You can test the API by calling the health endpoint:

```bash
# Basic health check
curl https://your-project.local/api/health

# API-specific health check (if registered)
curl https://your-project.local/api/public/v1/health
```

The API path prefix is configurable via the extension configuration (default: `/api/`).

## API Registration

To register a new API, you can use the `ApiRegistry` service in your `ext_localconf.php`:

```php
use SGalinski\SgApiCore\Service\ApiRegistry;
use TYPO3\CMS\Core\Utility\GeneralUtility;

$apiRegistry = GeneralUtility::makeInstance(ApiRegistry::class);
$apiRegistry->registerApi('public', ['1']);
$apiRegistry->registerApi('partner', ['1', '2']);
```

## Routing

Endpoints are defined using PHP attributes on controller methods:

```php
use SGalinski\SgApiCore\Attribute\ApiRoute;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Http\JsonResponse;

class MyController {
    #[ApiRoute(path: '/my-endpoint', methods: ['GET'], apiId: 'public', version: '1')]
    public function myAction(ServerRequestInterface $request): ResponseInterface {
        return new JsonResponse(['message' => 'Hello World']);
    }
}
```

### Endpoint Filtering

By default, an endpoint is available for all registered APIs and versions. You can restrict an endpoint to a specific
API or version by using the `apiId` and `version` properties of the `ApiRoute` attribute:

```php
// Only available for /api/public/v1/...
#[ApiRoute(path: '/public-only', methods: ['GET'], apiId: 'public', version: '1')]

// Available for all APIs in version 1
#[ApiRoute(path: '/v1-global', methods: ['GET'], version: '1')]
```

The router dynamically filters the available routes based on the `apiId` and `version` extracted from the request URL.

### Why not standard TYPO3 Routing?

Standard TYPO3 routing (via Site Configuration and Enhancers) is tightly coupled to the page tree and site handling. For
a high-performance data API, we chose `nikic/fast-route` for several reasons:

- **Performance**: It is extremely fast and operates independently of the TYPO3 page tree resolution.
- **Decoupling**: The API layer remains lean and isn't affected by frontend-related routing logic or redirects.
- **Flexibility**: Attributes allow for a modern, developer-friendly way to define endpoints directly in the controller,
  similar to Symfony or other modern frameworks.
- **Multi-API & Versioning**: It simplifies the implementation of complex patterns like `/api/{apiId}/v{version}/...`
  without requiring complex Enhancer configurations.

This approach allows the API to function as a specialized layer that intercepts requests before they hit the standard
frontend processing.

## Multi-Tenancy

Every API request runs in a **Tenant Context**. By default, the tenant is derived from the **TYPO3 Site** (Site
Identifier).

### Tenant Context

The `TenantContext` is available in the request attributes as `api.tenant`. It also contains the full TYPO3 Site object if the `SiteTenantResolver` was used:

```php
use SGalinski\SgApiCore\Context\TenantContext;

public function myAction(ServerRequestInterface $request): ResponseInterface {
    /** @var TenantContext $tenantContext */
    $tenantContext = $request->getAttribute('api.tenant');
    $tenantId = $tenantContext->getTenantId();

    // Access the TYPO3 Site object
    $site = $tenantContext->getSite();
    if ($site) {
        $configuration = $site->getConfiguration();
        // Access apicore specific settings if stored in site config
        $mySetting = $configuration['apicore']['my_setting'] ?? 'default';
    }
    // ...
}
```

### Tenant Resolution

The extension uses a `TenantResolverChain` to determine the tenant. The following resolvers are active by default:

1. **SiteTenantResolver**: Derives the tenant from the TYPO3 Site.
2. **HeaderTenantResolver**: Checks for an `X-Tenant-Id` header.

### Configuration

You can configure how the `SiteTenantResolver` derives the `tenantId` via the extension configuration:

- `siteTenantIdSource`: `identifier` (default), `baseHost`, or `rootPageId`.
- `onMissingTenant`: The HTTP status code to return when no tenant can be resolved (default: `404`).

### Custom Tenant Resolvers

You can implement your own tenant resolution logic by creating a class that implements `TenantResolverInterface`:

```php
namespace MyVendor\MyExtension\Service\Tenant;

use Psr\Http\Message\ServerRequestInterface;
use SGalinski\SgApiCore\Service\Tenant\TenantContextResult;
use SGalinski\SgApiCore\Service\Tenant\TenantResolverInterface;
use SGalinski\SgApiCore\Context\TenantContext;

class MyCustomResolver implements TenantResolverInterface {
    public function resolve(ServerRequestInterface $request): TenantContextResult {
        $myId = $request->getQueryParams()['tenant'] ?? null;
        if ($myId) {
            return TenantContextResult::success(new TenantContext($myId));
        }
        return TenantContextResult::error('Tenant query parameter missing');
    }
}
```

To register your resolver, add it to the `TenantResolverChain` in your `Services.php`:

```php
$services->set(TenantResolverChain::class)
    ->arg('$resolvers', [
        Configurator\service(SiteTenantResolver::class),
        Configurator\service(MyCustomResolver::class),
    ]);
```

## Security

The extension provides a robust security layer based on Bearer Token authentication and scope-based authorization.

### Bearer Token Authentication

Authentication is handled by the `BearerTokenProvider`. It expects a token in the `Authorization` header:

```bash
Authorization: Bearer <your-token>
```

Tokens are stored in the `tx_apicore_token` table. The system supports both **Plaintext tokens** and **JWTs**.
The `BearerTokenProvider` will automatically detect the token type.

#### Token Storage & Lookup

Tokens are bound to a **Tenant** and an **API**. Additionally, tokens should only be managed at the **Root Page** (pid 0
or root of a site) to support multi-domain setups in TYPO3.

#### Creating a Token (Manual)

1. Generate a secure random string or a JWT.
2. Insert a record into `tx_apicore_token` (at the root level):
    - `tenant_id`: The ID of the tenant (e.g., site identifier).
    - `api_id`: The ID of the API (e.g., `public`).
    - `token`: The plaintext token or JWT string.
    - `scopes`: A JSON array of scopes (e.g., `["read", "write"]`).
    - `expires_at`: (Optional) Unix timestamp.

### Scope-based Authorization

You can restrict access to endpoints based on scopes using the `#[RequireScopes]` attribute:

```php
use SGalinski\SgApiCore\Attribute\ApiRoute;
use SGalinski\SgApiCore\Attribute\RequireScopes;

class MyController {
    #[ApiRoute(path: '/secure-data', methods: ['GET'], apiId: 'public', version: '1')]
    #[RequireScopes(['data:read'])]
    public function secureAction(ServerRequestInterface $request): ResponseInterface {
        // ...
    }
}
```

#### How Scopes Work

- **If an endpoint has NO `#[RequireScopes]` attribute**: It is publicly accessible (still requires a valid token if any
  `LoginProvider` is active, unless you implement a public provider).
- **If an endpoint has an EMPTY `#[RequireScopes([])]` attribute**: It still requires a valid authentication but no
  specific scope.
- **If an endpoint has Scopes defined**: The authenticated `AuthContext` must have **ALL** the specified scopes to
  access the endpoint.

If a request is made without a valid token or without the required scopes, the API will return a **401 Unauthorized** or
**403 Forbidden** Problem JSON response.

### Auth Context

If a request is authenticated, the `AuthContext` is available in the request attributes:

```php
use SGalinski\SgApiCore\Security\AuthContext;

public function myAction(ServerRequestInterface $request): ResponseInterface {
    /** @var AuthContext $authContext */
    $authContext = $request->getAttribute('api.auth');
    if ($authContext) {
        $tokenUid = $authContext->getTokenUid();
        $scopes = $authContext->getScopes();
    }
}
```

### Custom Login Providers

You can extend the security layer by implementing your own `LoginProviderInterface`:

```php
namespace MyVendor\MyExtension\Security;

use Psr\Http\Message\ServerRequestInterface;use SGalinski\SgApiCore\Security\AuthContext;use SGalinski\SgApiCore\Security\LoginProviderInterface;

class MyLoginProvider implements LoginProviderInterface {
    public function authenticate(ServerRequestInterface $request, string $apiId, string $tenantId): ?AuthContext {
        // Your custom authentication logic (e.g., check a custom header or session)
        // Returns an AuthContext object on success, or null on failure.
    }
}
```

To register your provider, add it to the `LoginProviderChain` in your `Services.php`:

```php
use SGalinski\SgApiCore\Security\BearerTokenProvider;use SGalinski\SgApiCore\Security\LoginProviderChain;use Symfony\Component\DependencyInjection\Loader\Configurator;

$services->set(LoginProviderChain::class)
    ->arg('$providers', [
        Configurator\service(BearerTokenProvider::class),
        Configurator\service(MyLoginProvider::class),
    ]);
```
