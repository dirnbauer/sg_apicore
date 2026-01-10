# Auto-CRUD Resources

`sg_apicore` allows you to expose TYPO3 database tables as API resources with full CRUD (Create, Read, Update, Delete)
support without writing any controller code.

## Resource Registration

Resources are registered in your extension's `ext_localconf.php` via the `ResourceRegistry` service.

```php
use SGalinski\SgApiCore\Service\ResourceRegistry;use TYPO3\CMS\Core\Utility\GeneralUtility;

$resourceRegistry = GeneralUtility::makeInstance(ResourceRegistry::class);

// Register tt_content as a resource for the 'public' API
$resourceRegistry->registerResource('public', 'tt_content', '/contents', [
    'allowedOperations' => ['list', 'get'],
    'readFields' => ['uid', 'pid', 'header', 'bodytext']
]);

// Register a custom table for the 'partner' API with full CRUD and scopes
$resourceRegistry->registerResource('partner', 'tx_myext_domain_model_item', '/items', [
    'allowedOperations' => ['list', 'get', 'create', 'update', 'delete'],
    'writeFields' => ['header', 'bodytext', 'pid'],
    'requiredScopes' => [
        'list' => ['partner:read'],
        'get' => ['partner:read'],
        'create' => ['partner:write'],
        'update' => ['partner:write'],
        'delete' => ['partner:write'],
    ]
]);
// Register pages as a resource for the 'public' API
$resourceRegistry->registerResource('public', 'pages', '/pages', [
    'allowedOperations' => ['list', 'get'],
    'readFields' => ['uid', 'pid', 'title', 'doktype', 'slug']
]);
```

## Configuration Options

* `table`: The TYPO3 table name.
* `basePath`: The base path for the resource endpoints (e.g., `/items`).
* `idField`: The field used to identify a single item (default: `uid`).
* `allowedOperations`: Array of enabled operations (`list`, `get`, `create`, `update`, `delete`).
* `readFields`: Whitelist of fields for output mapping (empty = all except internal fields).
* `writeFields`: Whitelist of fields accepted for `create` and `update`.
* `requiredScopes`: Associative array mapping operations to required scope arrays.

## Generated Endpoints

Based on the configuration, the following endpoints are automatically generated:

* `GET /api/{apiId}/v{version}/{basePath}`: List resources (supports pagination, sorting, filtering).
* `GET /api/{apiId}/v{version}/{basePath}/{id}`: Get a single resource.
* `POST /api/{apiId}/v{version}/{basePath}`: Create a new resource.
* `PATCH /api/{apiId}/v{version}/{basePath}/{id}`: Update an existing resource.
* `DELETE /api/{apiId}/v{version}/{basePath}/{id}`: Delete a resource.

## List Operation Features

### Pagination

Use `page` and `perPage` query parameters:
`GET /api/public/v1/contents?page=2&perPage=20`

### Sorting

Use the `sort` query parameter. Prefix with `-` for descending order:
`GET /api/public/v1/contents?sort=header` (ASC)
`GET /api/public/v1/contents?sort=-uid` (DESC)

### Filtering

Use the `filter` query parameter with field names:
`GET /api/public/v1/contents?filter[header]=Welcome`

You can also use arrays for IN-queries:
`GET /api/public/v1/contents?filter[uid][]=1&filter[uid][]=2`

Only fields defined in `readFields` (or all if empty) can be used for filtering.

## Persistence & DataHandler

For `create`, `update`, and `delete` operations, the extension uses the TYPO3 `DataHandler`. This ensures that:

* All TYPO3 hooks are executed.
* Reference indexing is updated.
* History and logging are maintained.
* Permissions are respected (if a backend user context exists).

Data provided in the request body is automatically mapped using the `TcaMapper`, handling types like booleans and dates
correctly.
