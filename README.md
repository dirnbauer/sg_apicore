# Ext: sg_apicore

<img src="https://www.sgalinski.de/typo3conf/ext/project_theme/Resources/Public/Images/logo.svg" alt=""/>

License: [GNU GPL, Version 2 or later](https://www.gnu.org/licenses/gpl-2.0.html)

Repository: https://github.com/dirnbauer/sg_apicore

Please report bugs here: https://github.com/dirnbauer/sg_apicore/issues

## Short Summary

Provides an API framework for TYPO3: multi-API and multi-version routing, site-aware tenants, attribute-based endpoint
configuration, OpenAPI output, structured logging, opaque bearer tokens, JWT user tokens, backend-session auth,
MCP tool exposure, Auto-CRUD resources, and custom endpoints.

Version `14.x` is TYPO3 v14-only. The package requires TYPO3 `^14.3`, `typo3/cms-workspaces` `^14.3`, and PHP `^8.3`.
TYPO3 13 compatibility is intentionally not maintained in this release line.

For detailed information, please refer to the documentation in [docs/](docs/).
For website-ready end-user communication, see `docs/Website-End-User-Documentation.md`.

## Installation

1. Install the extension via composer:
   ```bash
   composer require sgalinski/sg-apicore
   ```

2. Activate the extension in the TYPO3 Extension Manager.

3. Make sure `typo3/cms-workspaces` is installed if Auto-CRUD writes should stage changes in a non-live workspace.

## Documentation

- [APIs and registration](docs/APIs.md)
- [Writing endpoints](docs/WritingEndpoints.md)
- [Authentication and scopes](docs/AuthScopes.md)
- [Auto-CRUD resources](docs/Resources.md)
- [TCA mapper](docs/TcaMapper.md)
- [OpenAPI](docs/OpenAPI.md)
- [MCP integration](docs/MCP.md)
- [Rate limiting](docs/RateLimiting.md)
- [Tenants](docs/Tenants.md)
- [Logging](docs/Logging.md)
- [Migration from sg_rest](docs/Migration.md)

## Development Quality Gates

The repository ships PHPStan configuration adapted from the TYPO3 v14.3 core setup and runs at max level:

```bash
composer phpstan
composer test
Build/Scripts/runTests.sh -s ci -p 8.3
```

The CI runner performs PHP linting, PHPStan at `level: max`, PHPUnit, and Composer audit.

Older `ddev-demo-setup-visual-editor` Composer files patched `sgalinski/sg-apicore` while the TYPO3 v14 upgrade branch
was still external. Those patch changes are now part of this repository, so this extension does not include a Composer
patch setup. The current demo project still uses `cweagans/composer-patches` for a `friendsoftypo3/content-blocks`
project patch, which does not belong in this extension package.

## Quick Start (3 Steps)

### 1. Register your Controller

Add your controller to `Configuration/Services.php` and tag it with `sg_apicore.router`:

```php
$services->set(MyController::class)
    ->tag('sg_apicore.router');
```

### 2. Define an Endpoint

Use the `#[ApiRoute]` attribute in your controller action:

```php
#[ApiRoute(path: '/hello', methods: ['GET'])]
public function helloAction(ServerRequestInterface $request): ResponseInterface {
    return $this->responseService->createSuccessResponse(['message' => 'Hello!']);
}
```

### 3. Access the API

Open your browser at `https://your-domain.local/api/public/v1/docs/ui` to see the generated Swagger UI and test your new
endpoint!

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
For a complete template with current best practices, see [ExampleController](docs/examples/ExampleController.php).

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
See [Writing Endpoints - Pagination](docs/WritingEndpoints.md#pagination).

## TCA Mapper

The `TcaMapper` service allows you to map TYPO3 database records to API response arrays based on the TCA.
See [TCA Mapper Documentation](docs/TcaMapper.md).

## Auto-CRUD Resources

You can expose TYPO3 tables as API resources with full CRUD support.
See [Auto-CRUD Resources Documentation](docs/Resources.md).

Auto-CRUD write operations use TYPO3 `DataHandler`. If a backend user is already authenticated, the current TYPO3
workspace is preserved. If a dedicated write backend user is configured, TYPO3 initializes that user's default
workspace; set `apiResourceWriteWorkspaceId` only when writes must be forced into a specific `sys_workspace` UID.
Raw read operations apply TYPO3 workspace visibility as well: live requests hide draft rows, workspace requests overlay
live rows with the current workspace version, and delete placeholders are omitted from API responses.

## OpenAPI Documentation

The extension automatically generates OpenAPI 3.0 specifications. You can access Swagger UI at
`/api/{apiId}/v{version}/docs/ui`. See [OpenAPI Documentation](docs/OpenAPI.md).

## MCP Integration

The extension can expose endpoints as MCP tools through a JSON-RPC endpoint:

- `POST /api/{apiId}/v{version}/mcp`
- `GET /api/{apiId}/v{version}/mcp` for the optional Streamable HTTP SSE channel

Use `api:mcp:list` to see the effective tool exposure after configuration, denylist, and endpoint-level exclusions.
See [MCP Documentation](docs/MCP.md).

## Backend Module

The extension provides a TYPO3 Backend Module under **System > API Core**.

- **APIs & Versions**: Overview and Swagger UI links.
- **Token Management**: Create and manage Opaque and Refresh tokens.
  - Supports optional FE-user bound tokens (`user_id` mapped to `fe_users`) for per-user API key flows.
  - Token list keeps current filter state while editing/revoking/regenerating.
- **Endpoints**: List of all registered endpoints and their requirements, including effective MCP exposure (tool names,
  attribute-based exclusions, and API/version mapping).

The module is available in both live and offline workspaces.

See [Authentication & Scopes - Backend](docs/AuthScopes.md#token-management-in-the-backend) for details.

## Logging

Comprehensive logging for API requests and responses, including request tracking via a unique Request ID.
See [Logging Documentation](docs/Logging.md).

## Multi-Tenancy

Every API request runs in a `TenantContext`, usually derived from the TYPO3 Site. Endpoints can be filtered by
tenants using the `tenants` property in the `#[ApiRoute]` attribute.
See [Tenants Documentation](docs/Tenants.md).

## Security & Authentication

Supports multiple auth modes (`public`, `token`, `user`, `backend`) and scope-based authorization.

- **API Level**: Define the default `authMode` as a **string** in the `ApiRegistry`.
- **Endpoint Level**: Override or extend the `authMode` using the `#[ApiRoute]` attribute (supports **string** or **array**, e.g., `['public', 'user']`).

See [Authentication & Scopes](docs/AuthScopes.md).

### CORS

CORS handling is provided by `ApiCorsMiddleware` and is enabled for API paths (`apiPathPrefix`) by default.

- Origin policy is **default deny**.
- Preflight requests (`OPTIONS` with `Access-Control-Request-Method`) are answered directly.
- Allowed origins are configured per API via `ApiRegistry::registerApi(..., $security)`:

```php
$apiRegistry->registerApi('partner', ['1'], [
    'authMode' => 'user',
    'authProviders' => ['beareropaquetokenprovider'],
    'cors' => [
        'allowedOrigins' => ['https://app.example.org'],
        'allowCredentials' => true,
    ],
]);
```

### Known Issues & Troubleshooting

#### Missing Authorization Header (Apache)

In some hosting environments (especially Apache with PHP via CGI/FastCGI), the `Authorization` header is stripped before it reaches PHP. If you experience "Authentication required" errors despite sending a valid token, add the following to your `.htaccess` file:

```apache
SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1
```

The extension includes a fallback to check for `HTTP_AUTHORIZATION` and `REDIRECT_HTTP_AUTHORIZATION`, but this server-side configuration is the most reliable fix.

## Legacy Support (Migration from sg_rest)

The extension provides a bridge to support legacy `sg_rest` clients. This includes:

- Middleware for mapping old URL patterns.
- Support for `fe_users` authentication tokens.
- Emulation of the old response format.

**Note**: Legacy support is **disabled by default**. See the [Migration Guide](docs/Migration.md) for details on how to
enable and use it.

## Architectural Decisions

For information on why we chose certain technologies and patterns, see our Architectural Decision Records at docs/adr/.
