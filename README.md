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

Endpoints are defined using PHP attributes on controller methods. In addition to the technical routing (`#[ApiRoute]`),
you can provide metadata for documentation and OpenAPI generation.

```php
use SGalinski\SgApiCore\Attribute\ApiRoute;
use SGalinski\SgApiCore\Attribute\ApiEndpoint;
use SGalinski\SgApiCore\Attribute\ApiQueryParam;
use SGalinski\SgApiCore\Attribute\ApiResponse;
use SGalinski\SgApiCore\Service\ResponseService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class MyController {
    protected ResponseService $responseService;

    public function __construct(ResponseService $responseService) {
        $this->responseService = $responseService;
    }

    #[ApiRoute(path: '/my-endpoint', methods: ['GET'], apiId: 'public', version: '1')]
    #[ApiEndpoint(
        summary: 'A short summary',
        description: 'A longer description of the endpoint.',
        tags: ['MyCategory']
    )]
    #[ApiQueryParam(name: 'filter', type: 'string', description: 'Filter the results')]
    #[ApiResponse(status: 200, description: 'Success')]
    public function myAction(ServerRequestInterface $request): ResponseInterface {
        return $this->responseService->createSuccessResponse(['message' => 'Hello World']);
    }
}
```

### Metadata Attributes

- **`#[ApiEndpoint]`**: Summary, description, tags, and schema references.
- **`#[ApiQueryParam]`**: Describes a query parameter (name, type, required, description).
- **`#[ApiPathParam]`**: Describes a path parameter (name, type, description).
- **`#[ApiResponse]`**: Describes a possible response (status, description, schema).

### Standardized Responses

The `ResponseService` provides a unified way to create JSON responses.

#### Success Responses

```php
return $this->responseService->createSuccessResponse($data, $meta, $status);
```

You can enable a **Response Envelope** in the extension configuration. If enabled (`responseEnvelope = 1`), all success
responses will be wrapped:

```json
{
    "data": {
        "id": 1,
        "name": "..."
    },
    "meta": {
        "total": 10
    }
}
```

#### Error Responses (Problem JSON)

Errors are returned using the **RFC 7807 (Problem Details for HTTP APIs)** format:

```php
return $this->responseService->createErrorResponse('Not Found', 'The item does not exist.', 404);
```

Result:

```json
{
    "title": "Not Found",
    "detail": "The item does not exist.",
    "status": 404,
    "type": "about:blank"
}
```

### Endpoint Filtering

By default, an endpoint is available for all registered APIs and versions. You can restrict an endpoint to specific
APIs, versions, or auth modes by using the properties of the `ApiRoute` attribute. These properties support both single
strings and arrays of strings:

```php
// Only available for /api/public/v1/...
#[ApiRoute(path: '/public-only', methods: ['GET'], apiId: 'public', version: '1')]

// Available for both public and partner APIs in version 1
#[ApiRoute(path: '/v1-shared', methods: ['GET'], apiId: ['public', 'partner'], version: '1')]

// Available for all APIs in version 1
#[ApiRoute(path: '/v1-global', methods: ['GET'], version: '1')]
```

The router dynamically filters the available routes based on the `apiId`, `version` and `authMode` extracted from the
request URL.
If a property is an array, the route is included if the current value matches any of the values in the array.
Multiple `#[ApiRoute]` attributes can also be used on the same method (repeatable attribute).

### Extbase Compatibility & Manual Mapping

Since this extension avoids the heavy Extbase bootstrap for performance reasons, automatic argument mapping and
validation (like in `ActionController`) are not available. However, you can still use Extbase's powerful mapping and
validation tools manually when needed.

#### Manual Property Mapping & Validation

To map a request body to an Extbase Domain Model and validate it, you can use the `PropertyMapper` and
`ValidatorResolver`. Note that in a manual flow, mapping and validation are separate steps:

```php
use TYPO3\CMS\Extbase\Property\PropertyMapper;
use TYPO3\CMS\Extbase\Validation\ValidatorResolver;
use MyVendor\MyExt\Domain\Model\MyModel;

class MyController {
    protected PropertyMapper $propertyMapper;
    protected ValidatorResolver $validatorResolver;
    protected ResponseService $responseService;

    public function __construct(
        PropertyMapper $propertyMapper,
        ValidatorResolver $validatorResolver,
        ResponseService $responseService
    ) {
        $this->propertyMapper = $propertyMapper;
        $this->validatorResolver = $validatorResolver;
        $this->responseService = $responseService;
    }

    public function createAction(ServerRequestInterface $request): ResponseInterface {
        $data = $request->getParsedBody()['myModel'] ?? [];

        try {
            // 1. Map to object
            /** @var MyModel $myModel */
            $myModel = $this->propertyMapper->convert($data, MyModel::class);

            // 2. Validate object
            $validator = $this->validatorResolver->getBaseValidatorConjunction(MyModel::class);
            $results = $validator->validate($myModel);

            if ($results->hasErrors()) {
                return $this->responseService->createErrorResponse('Validation Failed', 'Invalid data.', 400);
            }
        } catch (\Exception $e) {
            return $this->responseService->createErrorResponse('Mapping Failed', $e->getMessage(), 400);
        }

        // ...
    }
}
```

### Pagination

The extension provides a `PaginationService` to handle consistent pagination across endpoints.

#### Using the PaginationService

```php
use SGalinski\SgApiCore\Service\PaginationService;

class MyController {
    protected PaginationService $paginationService;
    protected ResponseService $responseService;

    public function __construct(PaginationService $paginationService, ResponseService $responseService) {
        $this->paginationService = $paginationService;
        $this->responseService = $responseService;
    }

    public function listAction(ServerRequestInterface $request): ResponseInterface {
        // 1. Get offset and limit from query parameters (with default/max values)
        $pagination = $this->paginationService->getPaginationParams($request);
        $offset = $pagination['offset'];
        $limit = $pagination['limit'];

        // 2. Fetch your data and total count
        $items = $this->myRepository->findSubset($limit, $offset);
        $total = $this->myRepository->countAll();

        // 3. Create response with pagination metadata
        return $this->responseService->createSuccessResponse(
            $items,
            $this->paginationService->buildPaginationMeta($total, $offset, $limit)
        );
    }
}
```

The pagination metadata will be included in the `meta` object of the response (if the envelope is enabled or if meta is
explicitly passed):

```json
{
    "data": [],
    "meta": {
        "total": 100,
        "offset": 10,
        "limit": 20,
        "count": 20
    }
}
```

## OpenAPI Documentation

The extension automatically generates OpenAPI 3.0 specifications based on your controller attributes.

### Accessing the Documentation

You can access the documentation for any registered API and version:

- **JSON Specification**: `/api/{apiId}/v{version}/docs.json`
- **Swagger UI**: `/api/{apiId}/v{version}/docs/ui`

For example: `https://your-project.local/api/public/v1/docs/ui`

### Logging

The extension provides a comprehensive logging system for API requests and responses. It can be configured globally and
overridden per endpoint.

#### Global Configuration

Logging can be configured in the Extension Configuration (Extension Manager or `settings.php`):

- **`enableLogging`**: Global toggle for API logging.
- **`logHeaders`**: Whether to log request headers.
- **`logBody`**: Whether to log the request body.
- **`logResponse`**: Whether to log the response body.
- **`redactKeys`**: Comma-separated list of keys to mask in logs (e.g., `password,token,access_token`).

#### Per-Endpoint Configuration

You can use the `#[ApiLogging]` attribute to override global logging settings for a specific method:

```php
use SGalinski\SgApiCore\Attribute\ApiLogging;

#[ApiLogging(
    enableLogging: true,
    logHeaders: true,
    logBody: true,
    logResponse: true
)]
public function sensitiveAction(ServerRequestInterface $request): ResponseInterface {
    // ...
}
```

#### Request Tracking

Every API request is assigned a unique **Request ID** (Correlation ID). This ID is:

- Added to the request attributes as `api.requestId`.
- Included in every log message.
- Returned in the response as the `X-Request-ID` header.

This allows you to easily trace an API call from the client through the logs.

### CLI Export

You can also export the OpenAPI specification to a file using the CLI:

```bash
# Export public API v1 to a file
typo3 api:openapi:generate --api=public --api-version=1 --out=public-api.json

# Output to stdout
typo3 api:openapi:generate --api=partner --api-version=2
```

### Security Schemes

The generated specification includes a `bearerAuth` security scheme. If an API is not in `public` mode, this security
scheme is automatically required for all its endpoints.

### Philosophy / Architectural Decisions

#### Why not standard TYPO3 Routing?

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

#### Why not Hydra or JSON-LD?

We consciously decided against using **Hydra** or **JSON-LD** for this API framework. While these standards provide
powerful hypermedia capabilities, they also introduce significant complexity and overhead:

- **Performance**: Standard JSON is faster to generate and parse.
- **Simplicity**: Most integration projects prefer a pragmatical REST/JSON approach that is straightforward to consume
  with standard tools and libraries.
- **Maintenance**: Maintaining a full Hydra-compliant API requires significantly more effort in terms of metadata and
  schema definitions.

Our focus is on a high-performance, easy-to-use, and developer-friendly API that integrates seamlessly into the TYPO3
ecosystem while following modern best practices.

### Logging

The extension provides a comprehensive logging system for API requests and responses. It can be configured globally and
overridden per endpoint.

#### Customizing Log Destination (e.g., Database or Separate File)

By default, API logs are written to a separate file: `var/log/sg_apicore.log`. This is pre-configured in the extension's
`LogService`.

You can override the log destination, writers, and processors in your TYPO3 configuration (`config/system/settings.php`)
under the `['LOG']` key.

**Example: Redirecting API logs to a different file**

```php
'LOG' => [
    'SGalinski' => [
        'SgApiCore' => [
            'Service' => [
                'LogService' => [
                    'writerConfiguration' => [
                        \TYPO3\CMS\Core\Log\LogLevel::INFO => [
                            \TYPO3\CMS\Core\Log\Writer\FileWriter::class => [
                                'logFile' => 'var/log/custom_api_filename.log'
                            ]
                        ]
                    ]
                ]
            ]
        ]
    ]
]
```

**Example: Logging to the Database (Visible in Backend)**

```php
'LOG' => [
    'SGalinski' => [
        'SgApiCore' => [
            'Service' => [
                'LogService' => [
                    'writerConfiguration' => [
                        \TYPO3\CMS\Core\Log\LogLevel::INFO => [
                            \TYPO3\CMS\Core\Log\Writer\DatabaseWriter::class => []
                        ]
                    ]
                ]
            ]
        ]
    ]
]
```

#### Global Configuration

Logging can be configured in the Extension Configuration (Extension Manager or `settings.php`):

- **`enableLogging`**: Global toggle for API logging.
- **`logHeaders`**: Whether to log request headers.
- **`logBody`**: Whether to log the request body.
- **`logResponse`**: Whether to log the response body.
- Redact keys: Comma-separated list of keys to mask in logs (e.g.,
  `password,token,authorization,secret,access_token,refresh_token`).

### Custom Controllers & Extensions

Other extensions can register their own controllers to the API by using the `sg_apicore.router` service tag in their
`Services.php`:

```php
$services->set(MyCustomController::class)
    ->tag('sg_apicore.router');
```

The `Router` will automatically collect all services tagged with `sg_apicore.router` and scan them for `#[ApiRoute]`
attributes.

#### Overriding or Disabling Default Controllers

Since default controllers like `TestController` or `UserAuthController` are registered as standard services in TYPO3's
Symfony DI, you can override or disable them in your own `Services.php`.

To **override** a default controller with your own implementation:

```php
// In your extension's Configuration/Services.php
$services->set(SGalinski\SgApiCore\Controller\TestController::class, MyCustomTestController::class)
    ->tag('sg_apicore.router');
```

To **disable** a default controller:

```php
// In your extension's Configuration/Services.php
$services->remove(SGalinski\SgApiCore\Controller\TestController::class);
```

### Route Filtering by Auth Mode

You can restrict endpoints to specific authentication modes (e.g., only available when the API is in `user` mode). This
is useful for internal API endpoints like login or refresh:

```php
#[ApiRoute(path: '/auth/login', methods: ['POST'], authMode: 'user')]
```

If an endpoint defines an `authMode`, it will only be available in APIs that are configured with that exact `authMode`.

## Multi-Tenancy

Every API request runs in a **Tenant Context**. By default, the tenant is derived from the **TYPO3 Site** (Site
Identifier).

### Tenant Context

The `TenantContext` is available in the request attributes as `api.tenant`. It also contains the full TYPO3 Site object
if the `SiteTenantResolver` was used:

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

Authentication and Authorization are handled by the `LoginProviderChain`. The system supports multiple auth modes and
token types.

### Auth Modes

You can configure the authentication mode per API (and version) in your `ext_localconf.php`:

- **public**: No authentication required by default (unless scopes are defined).
- **token**: Opaque Bearer Token required.
- **user**: Opaque Access Token (default) or JWT Access Token (optional) required. Supports Refresh Token flow.

Example configuration:

```php
$apiRegistry->registerApi('partner', ['1'], [
    'authMode' => 'token',
    'authProviders' => ['beareropaquetokenprovider']
]);
```

### Opaque Bearer Token (Standard)

Standard tokens are random strings (Bearer) and are stored as **SHA-256 hashes** in the database for security.

#### Creating a Token (Manual)

1. Generate a secure random string (the token).
2. Calculate the SHA-256 hash of the token.
3. Insert a record into `tx_apicore_token`:
    - `token_hash`: The SHA-256 hash.
    - `tenant_id`: The ID of the tenant.
    - `api_id`: The ID of the API.
    - `scopes`: JSON array of scopes.

### User-Level Auth (Access & Refresh Tokens)

For `user` mode, the system supports a standard Access/Refresh token flow.

#### 1. Login (Initial Authentication)

The extension provides a default login endpoint that validates TYPO3 Frontend User credentials and returns an initial *
*Access Token** and **Refresh Token**:

- **URL**: `POST /api/{apiId}/v{version}/auth/login`
- **Parameters**: `username`, `password`
- **Returns**: `access_token`, `refresh_token`, `token_type`, `expires_in`

The `UserAuthController` handles this process. It finds the user in `fe_users` and verifies the password hash.

##### User Storage and Site Awareness

By default, the controller looks for users in the current **TYPO3 Site Root**. You can customize the storage pages
(PIDs) in several ways:

1. **sg_account Integration**: If the extension `sg_account` is installed, it will automatically use the storage
   configuration defined in your **Account Configuration** (Main Configuration).
2. **Site Configuration**: You can explicitly define the storage PIDs in your `site.yaml`:
   ```yaml
   apicore:
     userStoragePids: "123,456"
   ```
3. **Fallback**: If none of the above is configured, it falls back to the root page ID of the current site.

It then uses the `TokenService` to generate the tokens.

#### 2. Refresh

To get a new Access Token after it expires, use the refresh endpoint with a valid Refresh Token:

- **URL**: `POST /api/{apiId}/v{version}/auth/refresh`
- **Parameters**: `refresh_token`
- **Returns**: `access_token`, `token_type`, `expires_in`

#### JWT Access Tokens (Optional)

You can enable JWTs for Access Tokens. In this case, the `JwtAccessTokenProvider` validates the token without a database
lookup for every request. Refresh tokens always remain opaque and database-backed.

JWT Requirements:

- Must have `exp` (expiry) and `jti` (unique ID) claims.
- Must have `tenantId` and `apiId` claims matching the request context.
- Should contain `userId` and `scopes` claims.

### Scope-based Authorization

You can restrict access to endpoints based on scopes using the `#[RequireScopes]` attribute:

```php
#[RequireScopes(['orders:read'])]
```

- **No attribute**: Publicly accessible if `authMode` is `public`.
- **Empty attribute `#[RequireScopes([])]`**: Requires valid authentication but no specific scope.
- **Scopes defined**: Requires a valid token with **ALL** specified scopes.

### Custom Login Providers

Implement `LoginProviderInterface` and register it in `Services.php`. To have it automatically added to the
`LoginProviderChain`, use the `sg_apicore.login_provider` tag:

```php
$services->set(MyCustomProvider::class)
    ->tag('sg_apicore.login_provider');
```

Note: If you use the tag, make sure the `LoginProviderChain` is configured to collect these tagged services. By default,
you can also manually add them to the `LoginProviderChain` definition in your `Services.php`:

```php
$services->set(LoginProviderChain::class)
    ->arg('$providers', [
        Configurator\service(BearerOpaqueTokenProvider::class),
        Configurator\service(MyCustomProvider::class),
    ]);
```
