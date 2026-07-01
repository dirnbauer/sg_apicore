# Writing Endpoints

In `sg_apicore`, endpoints are defined via standard PHP classes (controllers) configured using PHP attributes.

## Recommended Template

Use `docs/examples/ExampleController.php` as the reference implementation. It covers:

- global and API-specific routes
- tenant and auth context usage
- query/path/body validation
- `RequireUser` and `RequireScopes`
- pagination with metadata
- response caching via `ApiCache`
- full TypoScript requirement via `RequireFullTypoScript`

## Controller Registration

For the router to recognize your controller class, it must be registered in `Configuration/Services.php` with the
`sg_apicore.router` tag:

```php
$services->set(MyCustomController::class)
	->tag('sg_apicore.router');
```

## Routing & Metadata

Use the `#[ApiRoute]` attribute to define the path and methods. Additional attributes serve the automatic OpenAPI
documentation.

```php
use SGalinski\SgApiCore\Attribute\ApiRoute;
use SGalinski\SgApiCore\Attribute\ApiEndpoint;
use SGalinski\SgApiCore\Attribute\ApiResponse;
use SGalinski\SgApiCore\Service\ResponseService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class MyController {
	public function __construct(
		protected ResponseService $responseService
	) {}

	#[ApiRoute(path: '/my-action/{id}', methods: ['GET'], apiId: 'public', version: '1', tenants: 'my-tenant')]
	#[ApiEndpoint(summary: 'Short description', tags: ['MyCategory'])]
	#[ApiResponse(status: 200, description: 'Success')]
	public function myAction(ServerRequestInterface $request, string $id): ResponseInterface {
		$data = ['id' => $id, 'name' => 'Test'];
		return $this->responseService->createSuccessResponse($data);
	}
}
```

### Endpoint Filtering

By default, an endpoint is available for all registered APIs and versions. You can restrict an endpoint to specific
APIs, versions, tenants, or auth modes by using the properties of the `ApiRoute` attribute:

```php
// Only available for /api/public/v1/...
#[ApiRoute(path: '/public-only', methods: ['GET'], apiId: 'public', version: '1')]

// Only available for specific tenants (Site-ID by default)
#[ApiRoute(path: '/tenant-specific', methods: ['GET'], tenants: 'citypower-tenant')]
#[ApiRoute(path: '/multi-tenant', methods: ['GET'], tenants: ['tenant-a', 'tenant-b'])]

// Available for both public and partner APIs in version 1
#[ApiRoute(path: '/v1-shared', methods: ['GET'], apiId: ['public', 'partner'], version: '1')]

// Publicly accessible even in a protected 'user' or 'token' API
#[ApiRoute(path: '/auth/login', methods: ['POST'], authMode: 'public')]
```

### MCP Exposure Control

If you use MCP, you can control per-endpoint exposure with `#[ApiMcp]`:

```php
use SGalinski\SgApiCore\Attribute\ApiMcp;

#[ApiRoute(path: '/seo/title', methods: ['POST'], apiId: 'sgai', version: '1')]
#[ApiMcp(name: 'sgai_generate_seo_title')]
public function seoTitleAction(ServerRequestInterface $request): ResponseInterface {
	// ...
}

#[ApiRoute(path: '/internal/debug', methods: ['GET'])]
#[ApiMcp(exclude: true)]
public function debugAction(ServerRequestInterface $request): ResponseInterface {
	// ...
}
```

See [MCP Integration](MCP.md) for global kill switch, denylist, and runtime details.

### Manual Property Mapping & Validation (Extbase Compatibility)

Since this extension avoids the Extbase bootstrap for performance reasons, automatic argument mapping is not available.
You can still use Extbase's tools manually:

```php
use TYPO3\CMS\Extbase\Property\PropertyMapper;
use TYPO3\CMS\Extbase\Validation\ValidatorResolver;

// Inside your controller action:
$myModel = $this->propertyMapper->convert($data, MyModel::class);
$results = $this->validatorResolver->getBaseValidatorConjunction(MyModel::class)->validate($myModel);
```

## Request Context

During request handling, `sg_apicore` enriches the PSR-7 request with attributes. Most relevant ones:

- `api.tenant` (`TenantContext`)
- `api.auth` (`AuthContext`, if authenticated)
- `api.requestId` (string)
- `frontend.typoscript` (when `#[RequireFullTypoScript]` is active)

## Parameter Types & Validation

The extension supports automatic validation of parameters based on the attributes used in your controller.

* **Path Parameters**: Defined via `#[ApiPathParam]`. Passed directly as arguments to the method (e.g., `$id`).
* **Query Parameters**: Defined via `#[ApiQueryParam]`. Access via `$request->getQueryParams()`.
* **Body Parameters**: Defined via `#[ApiBodyParam]`. Access via `$request->getParsedBody()` (JSON bodies are
  automatically parsed by the middleware).

### Validation Constraints

You can add various validation constraints to these attributes:

* `type`: The expected data type (`string`, `integer`, `float`, `boolean`, `array`, `file`).
* `required`: Whether the parameter must be present.
* `pattern`: An optional regular expression (PCRE) the value must match.
* `min` / `max`: For numeric types, defines the inclusive range.
* `minLength` / `maxLength`: For string types, defines the length range.
* `requiredIf`: Makes a field required only if a certain condition is met.
    * Example: `requiredIf: 'type=special'` (field is required if the field `type` has the value `special`).
    * Example: `requiredIf: 'otherField'` (field is required if the field `otherField` is present and not empty).

### Requirement of TypoScript

Some endpoints might require the full TYPO3 TypoScript configuration for parsing (e.g., using `lib.parseFunc_RTE`) or
rendering content. By default, the API context only provides a minimal TypoScript stub for performance reasons.

You can signal that an endpoint requires the full TypoScript to be loaded by using the `#[RequireFullTypoScript]`
attribute:

```php
#[ApiRoute(path: '/render-content', methods: ['GET'])]
#[RequireFullTypoScript]
public function renderAction(ServerRequestInterface $request): ResponseInterface {
	// The full TypoScript setup is now available via $request->getAttribute('frontend.typoscript').
}
```

**Note**: Loading the full TypoScript can significantly impact the performance of the API request as it triggers
the TYPO3 TypoScript parsing and potentially caching mechanisms. Only use it if absolutely necessary.

Example:

```php
#[ApiRoute(path: '/register', methods: ['POST'])]
#[ApiBodyParam(name: 'username', type: 'string', minLength: 5, maxLength: 20, pattern: '/^[a-z0-9_]+$/')]
#[ApiBodyParam(name: 'age', type: 'integer', min: 18)]
#[ApiBodyParam(name: 'type', type: 'string')]
#[ApiBodyParam(name: 'company_name', required: false, requiredIf: 'type=business')]
public function registerAction(ServerRequestInterface $request): ResponseInterface {
	// ...
}
```

### Automatic Validation from TCA

For Auto-CRUD resources, validation rules are automatically derived from the TYPO3 TCA:

* `eval => required` results in `required: true`.
* `eval => email` results in a standard email regex pattern.
* `type => number` results in `integer` or `float` type validation.
* `config => range` results in `min` and `max` validation.

## Standardized Responses

Use the `ResponseService` for consistent JSON responses:

* `createSuccessResponse($data, $meta, $status)`: Generates a success response (optional with envelope).
* `createErrorResponse($title, $detail, $status)`: Generates an RFC 7807 compliant error message.

## Pagination

The extension provides a `PaginationService` to handle consistent pagination across endpoints.

### Using the PaginationService

```php
use SGalinski\SgApiCore\Service\PaginationService;

class MyController {
	public function __construct(
		protected PaginationService $paginationService,
		protected ResponseService $responseService
	) {}

	#[ApiQueryParam(name: 'page', type: 'integer', description: 'The page number (1-based)')]
	#[ApiQueryParam(name: 'limit', type: 'integer', description: 'Maximum number of items to return')]
	public function listAction(ServerRequestInterface $request): ResponseInterface {
		$pagination = $this->paginationService->getPaginationParams($request);
		$offset = $pagination['offset'];
		$limit = $pagination['limit'];

		$items = $this->myRepository->findSubset($limit, $offset);
		$total = $this->myRepository->countAll();

		return $this->responseService->createSuccessResponse(
			$items,
			$this->paginationService->buildPaginationMeta($total, $offset, $limit)
		);
	}
}
```

The pagination metadata will be included in the `meta` object of the response (if the envelope is enabled or if meta is
explicitly passed).

## Performance & Caching

The extension includes a built-in response caching system based on the TYPO3 Caching Framework.

### Default Behavior

Caching is **enabled by default** for all `GET` requests. The cache key automatically varies by:

- The full Request URI
- The current Site and Language
- The Frontend User Groups (sorted to ensure consistency)

Public cache reads are bypassed for requests that explicitly ask for a fresh response (`Cache-Control: no-cache` or
`Pragma: no-cache`) and for requests running in an active TYPO3 backend user context.

### Controlling Cache

You can customize or disable caching using the `#[ApiCache]` attribute:

```php
use SGalinski\SgApiCore\Attribute\ApiCache;

// Disable caching for this endpoint
#[ApiRoute(path: '/live-data', methods: ['GET'])]
#[ApiCache(enabled: false)]
public function liveAction(): ResponseInterface { ... }

// Customize caching
#[ApiRoute(path: '/heavy-list', methods: ['GET'])]
#[ApiCache(lifetime: 3600, tags: ['news', 'category_1'])]
public function listAction(ServerRequestInterface $request): ResponseInterface {
	// This response will be cached for 1 hour with specific tags.
}
```

### Cache Configuration

The `#[ApiCache]` attribute supports the following properties:

* `enabled` (bool): Whether caching is enabled. Default is `true`.
* `lifetime` (int): Cache lifetime in seconds. Default is `0` (system default).
* `tags` (array): A list of cache tags. Highly recommended for selective invalidation.
* `useUserGroups` (bool): If `true` (default), the cache key varies by the user groups of the authenticated frontend
  user.
* `useLanguage` (bool): If `true` (default), the cache varies by the current language.
* `additionalVary` (array): Additional query parameters or header names that should affect cache keys.

### Cache Invalidation

The system automatically performs tag-based invalidation if a writing request (`POST`, `PATCH`, `DELETE`) is made to an
endpoint that defines the same cache tags.

For example, if a `POST` request is sent to an endpoint with `#[ApiCache(tags: ['news'])]`, all cache entries with the
`news` tag will be flushed.

**Note**: For automatic resources (CRUD), caching and invalidation are handled automatically using the table name as
a cache tag.

### Request-Driven Cache Bypass

The middleware also respects request context when deciding whether to read from or write to the API response cache:

* `Cache-Control: no-cache` or `Pragma: no-cache`: bypasses cache reads, but the freshly generated response may still be
  stored afterwards.
* `Cache-Control: no-store`: bypasses cache reads and skips storing the response.
* Active TYPO3 backend user context: bypasses cache reads and writes for the request.

### Clearing Cache manually

You can clear the entire API response cache in the TYPO3 Backend via the "Flush cache" menu (lightning icon) using
the **"Clear API Cache"** entry. This entry is only available to backend administrators.

### Cache Status Headers

You can monitor the cache status via the `X-TYPO3-API-Cache` HTTP header in the response:

* `HIT`: The response was served directly from the cache.
* `MISS`: The response was newly generated and then stored in the cache.
