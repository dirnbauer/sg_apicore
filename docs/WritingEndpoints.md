# Writing Endpoints

In `sg_apicore`, endpoints are defined via standard PHP classes (controllers) configured using PHP attributes.

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

    #[ApiRoute(path: '/my-action/{id}', methods: ['GET'], apiId: 'public', version: '1')]
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
APIs, versions, or auth modes by using the properties of the `ApiRoute` attribute:

```php
// Only available for /api/public/v1/...
#[ApiRoute(path: '/public-only', methods: ['GET'], apiId: 'public', version: '1')]

// Available for both public and partner APIs in version 1
#[ApiRoute(path: '/v1-shared', methods: ['GET'], apiId: ['public', 'partner'], version: '1')]

// Publicly accessible even in a protected 'user' or 'token' API
#[ApiRoute(path: '/auth/login', methods: ['POST'], authMode: 'public')]
```

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

## Parameter Types & Validation

The extension supports automatic validation of parameters based on the attributes used in your controller.

* **Path Parameters**: Defined via `#[ApiPathParam]`. Passed directly as arguments to the method (e.g., `$id`).
* **Query Parameters**: Defined via `#[ApiQueryParam]`. Access via `$request->getQueryParams()`.
* **Body Parameters**: Defined via `#[ApiBodyParam]`. Access via `$request->getParsedBody()` (JSON bodies are
  automatically parsed by the middleware).

### Validation Constraints

You can add validation constraints to these attributes:

* `type`: The expected data type (`string`, `integer`, `float`, `boolean`).
* `required`: Whether the parameter must be present (default: `true` for body, `false` for query).
* `pattern`: An optional regular expression (PCRE) the value must match.

Example:

```php
#[ApiRoute(path: '/search', methods: ['GET'])]
#[ApiQueryParam(name: 'query', type: 'string', required: true, pattern: '/^[a-z0-9 ]+$/i')]
#[ApiQueryParam(name: 'page', type: 'integer', description: 'Page number')]
public function searchAction(ServerRequestInterface $request): ResponseInterface {
    // If validation fails, the router returns a 400 Bad Request before this method is called.
}
```

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
explicitly passed).

## Performance & Caching

The extension includes a built-in response caching system based on the TYPO3 Caching Framework.

### Default Behavior

Caching is **enabled by default** for all `GET` requests. The cache key automatically varies by:

- The full Request URI
- The current Site and Language
- The Frontend User Groups (sorted to ensure consistency)

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
* `additionalVary` (array): A list of additional query parameters or header names to vary the cache key by.

### Cache Invalidation

The system automatically performs tag-based invalidation if a writing request (`POST`, `PATCH`, `DELETE`) is made to an
endpoint that defines the same cache tags.

For example, if a `POST` request is sent to an endpoint with `#[ApiCache(tags: ['news'])]`, all cache entries with the
`news` tag will be flushed.

**Note**: For automatic resources (CRUD), caching and invalidation are handled automatically using the table name as
a cache tag.

### Clearing Cache manually

You can clear the entire API response cache in the TYPO3 Backend via the "Flush cache" menu (lightning icon) using
the **"Clear API Cache"** entry.

### Cache Status Headers

You can monitor the cache status via the `X-TYPO3-API-Cache` HTTP header in the response:

* `HIT`: The response was served directly from the cache.
* `MISS`: The response was newly generated and then stored in the cache.
