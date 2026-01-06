# Writing Endpoints

In `sg_apicore`, endpoints are defined via standard PHP classes (controllers) configured using PHP attributes.

## Controller Registration

For your controller class to be recognized by the router, it must be registered in `Configuration/Services.php` with the
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

## Parameter Types

* **Path Parameters**: Passed directly as arguments to the method (e.g., `$id`).
* **Query Parameters**: Via `$request->getQueryParams()`.
* **Body Parameters**: Via `$request->getParsedBody()` (JSON bodies are automatically parsed by the middleware).

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
