# Ext: sg_apicore

<img src="https://www.sgalinski.de/typo3conf/ext/project_theme/Resources/Public/Images/logo.svg" alt=""/>

License: [GNU GPL, Version 2](https://www.gnu.org/licenses/gpl-2.0.html)

Repository: https://gitlab.sgalinski.de/typo3/sg_apicore

Please report bugs here: https://gitlab.sgalinski.de/typo3/sg_apicore/-/issues

## Short Summary

Provides an API framework for TYPO3: Multi-API, Multi-Tenants, Attribute-based endpoint configuration, Logging,
Token JWT Bearer auth, User auth, Entity CRUD registration, Custom Endpoints.

For detailed information, please refer to the Documentation in docs/.

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
- `docs/`: Technical documentation and guides.
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

To register a new API, you use the `ApiRegistry` service. Detailed configuration options can be found in
the [APIs & Registration Documentation](docs/APIs.md).

```php
use SGalinski\SgApiCore\Service\ApiRegistry;
use TYPO3\CMS\Core\Utility\GeneralUtility;

$apiRegistry = GeneralUtility::makeInstance(ApiRegistry::class);
$apiRegistry->registerApi('public', ['1']);
```

## Writing Endpoints

Endpoints are defined using PHP attributes on controller methods. See [Writing Endpoints](docs/WritingEndpoints.md) for
a full guide.

```php
#[ApiRoute(path: '/my-endpoint', methods: ['GET'], apiId: 'public', version: '1')]
public function myAction(ServerRequestInterface $request): ResponseInterface {
    return $this->responseService->createSuccessResponse(['message' => 'Hello World']);
}
```

### Standardized Responses

The `ResponseService` provides a unified way to create JSON responses, following RFC 7807 for errors.
See [Writing Endpoints - Responses](docs/WritingEndpoints.md#standardized-responses).

### Pagination

The extension provides a `PaginationService` to handle consistent pagination across endpoints.
See [Writing Endpoints - Pagination](docs/WritingEndpoints.md#pagination) (Note: Add pagination details to docs if
missing).

## TCA Mapper

The `TcaMapper` service allows you to map TYPO3 database records to API response arrays based on the TCA.
See [TCA Mapper Documentation](docs/TcaMapper.md).

## OpenAPI Documentation

The extension automatically generates OpenAPI 3.0 specifications. You can access Swagger UI at
`/api/{apiId}/v{version}/docs/ui`. See [OpenAPI Documentation](docs/OpenAPI.md).

## Backend Module

The extension provides a TYPO3 Backend Module under **System > API Core**.

- **APIs & Versions**: Overview and Swagger UI links.
- **Token Management**: Create and manage Opaque and Refresh tokens.
- **Endpoints**: List of all registered endpoints and their requirements.

See [Authentication & Scopes - Backend](docs/AuthScopes.md#token-management-in-the-backend) for details.

## Logging

Comprehensive logging for API requests and responses, including request tracking via a unique Request ID.
See [Logging Documentation](docs/Logging.md).

## Multi-Tenancy

Every API request runs in a `TenantContext`, usually derived from the TYPO3 Site.
See [Tenants Documentation](docs/Tenants.md).

## Security & Authentication

Supports multiple auth modes (public, token, user) and scope-based authorization.
See [Authentication & Scopes](docs/AuthScopes.md).

## Architectural Decisions

For information on why we chose certain technologies and patterns, see our [Architectural Decision Records](docs/adr/).
