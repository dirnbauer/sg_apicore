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
    'deleteMode' => 'hard',
    'tags' => ['Items'],
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
* `fieldConfiguration`: Map of table names to their field configurations. Allows controlling `allowed` and `excluded`
  fields for both the main record and related records (when resolved).
    * Example:
      ```php
      'fieldConfiguration' => [
          'tx_myext_domain_model_item' => [
              'allowed' => ['uid', 'header', 'related_item'],
          ],
          'tx_myext_domain_model_related' => [
              'excluded' => ['internal_secret'],
          ]
      ]
      ```
* `deleteMode`: `soft` (default) uses DataHandler delete, `hard` deletes the DB record directly (no TYPO3 audit log).
* `rateLimit`: Optional rate limit overrides for this resource (see `RateLimiting.md`).
* `requiredScopes`: Associative array mapping operations to required scope arrays.
* `resolveDepth`: Default recursion depth for resolving relations (default: `1`).

If `writeFields` is empty, the OpenAPI request body is generated from `readFields`. If both are empty, the request body
is generated from the table TCA (excluding `uid`).

## Generated Endpoints

Based on the configuration, the following endpoints are automatically generated:

* `GET /api/{apiId}/v{version}/{basePath}`: List resources (supports pagination, sorting, filtering).
* `GET /api/{apiId}/v{version}/{basePath}/{id}`: Get a single resource.
* `POST /api/{apiId}/v{version}/{basePath}`: Create a new resource.
* `PATCH /api/{apiId}/v{version}/{basePath}/{id}`: Update an existing resource.
* `DELETE /api/{apiId}/v{version}/{basePath}/{id}`: Delete a resource (returns 204 without a response body).

## List Operation Features

### Pagination

Use `page` and `limit` query parameters:
`GET /api/public/v1/contents?page=2&limit=20`

**Note**: `perPage` is also supported as an alias for `limit` for backward compatibility.

### Sorting

Use the `sort` query parameter. Prefix with `-` for descending order:
`GET /api/public/v1/contents?sort=header` (ASC)
`GET /api/public/v1/contents?sort=-uid` (DESC)

### Filtering

Use the `filter` query parameter with field names:
`GET /api/public/v1/contents?filter[header]=Welcome`

You can also use arrays for IN-queries:
`GET /api/public/v1/contents?filter[uid][]=1&filter[uid][]=2`

Only fields defined in `readFields` (or whitelisted in `fieldConfiguration` or all if empty) can be used for filtering.

## Persistence & DataHandler

For `create`, `update`, and `delete` operations, the extension uses the TYPO3 `DataHandler`. This ensures that:

* All TYPO3 hooks are executed.
* Reference indexing is updated.
* History and logging are maintained.
* Permissions are respected (if a backend user context exists).

Data provided in the request body is automatically mapped using the `TcaMapper`, handling types like booleans and dates
correctly.

### Backend User for Resource Writes

Auto-CRUD write operations (`create`, `update`, `delete`) can run under a dedicated backend user. Configure the backend
user UID via the extension configuration key `apiResourceWriteBackendUserId`.

If set, the configured backend user's permissions and groups are used for resource write operations. If not set (or 0),
the extension keeps the admin bypass behavior for write operations.

This setting only affects Auto-CRUD resource endpoints and does not apply to custom controllers.

### Workspaces

`sg_apicore` uses TYPO3's `DataHandler` for Auto-CRUD writes, so workspace handling is delegated to TYPO3 instead of
being implemented separately in the extension.

By default, `apiResourceWriteWorkspaceId = -1` keeps the current/default backend user workspace:

* Requests authenticated with an existing backend user keep that user's selected workspace.
* Requests using `apiResourceWriteBackendUserId` use TYPO3's workspace initialization for that user.
* Set `apiResourceWriteWorkspaceId = 0` only when writes must go directly to live.
* Set `apiResourceWriteWorkspaceId` to a positive `sys_workspace.uid` to force writes into that workspace.

For production write endpoints, configure a dedicated backend user with the required page mounts, table permissions, and
workspace access. Avoid the admin bypass fallback for public or partner-facing write APIs.

TYPO3 workspaces do not version physical FAL files. When APIs create or update file references in workspace content,
upload files with unique, non-guessable names and avoid overwriting existing files.
