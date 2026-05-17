# TCA Mapper

The `TcaMapper` service allows TYPO3 database records (arrays) to be automatically converted into API-compliant
structures. It uses the information from the Table Configuration Array (TCA).
This document applies to the TYPO3 `14.x` release line.

## Features

* **Automatic Whitelisting**: Internal TYPO3 fields (such as `tstamp`, `crdate`, `deleted`, and workspace metadata) are
  excluded by default.
* **Type Conversion**:
    * Booleans (for `type => check`)
    * Integers (for `eval => int` or `type => number`)
    * Date formats (ISO 8601 strings for `inputDateTime`)
* **Multi-Value Support**: Comma-separated lists (e.g., relations) are automatically converted into arrays.
* **Relation Resolution**: Automatically resolves FAL (sys_file_reference), 1:n (IRRE) and M:M (MM table) relations.
* **Custom Callbacks**: Support for computed or dynamic fields during mapping.
* **Field Renaming**: Ability to expose database fields under different names in the API.
* **Field Configurations**: Granular control over allowed/excluded fields per table, including nested relations.

## Usage in Controller

```php
use SGalinski\SgApiCore\Mapper\TcaMapper;

class MyController {
    public function __construct(
        protected TcaMapper $tcaMapper,
        protected ResponseService $responseService
    ) {}

    public function getAction(ServerRequestInterface $request, string $id): ResponseInterface {
        $record = $this->myRepository->findByUid($id);

        // Mapping for tt_content
        $mappedData = $this->tcaMapper->mapRecord('tt_content', $record);

        return $this->responseService->createSuccessResponse($mappedData);
    }
}
```

## Restricting Fields

You can explicitly specify which fields should be mapped:

```php
$allowedFields = ['uid', 'pid', 'header', 'bodytext'];
$mappedData = $this->tcaMapper->mapRecord('tt_content', $record, $allowedFields);
```

## Mapping Multiple Records

Use `mapRecords()` for lists:

```php
$records = $this->myRepository->findAll();
$mappedList = $this->tcaMapper->mapRecords('tx_myext_table', $records);
```

## Advanced Features

### Relation Resolution

The mapper can resolve relations automatically if a `resolveDepth` > 0 is provided:

```php
$mappedData = $this->tcaMapper->mapRecord(
    'tx_myext_table',
    $record,
    resolveDepth: 1
);
```

Supported relations:

- **FAL**: `sys_file_reference` (images, documents) are always resolved to an array of file objects.
- **1:n**: IRRE relations using `foreign_field` and optional `foreign_table_field`.
- **M:M**: Relations using an MM table.
- **Select/Group**: Fields with a `foreign_table` and comma-separated UIDs.

For Auto-CRUD resources, workspace overlays are applied before records are handed to the mapper. The mapper therefore
receives the effective record for the current workspace and can keep workspace metadata out of the public payload.

### Field Configuration

You can pass a `fieldConfiguration` to control which fields are returned for specific tables, which is especially useful
for nested relations:

```php
$fieldConfiguration = [
    'tx_myext_table' => [
        'allowed' => ['uid', 'title', 'related_item']
    ],
    'tx_myext_related' => [
        'excluded' => ['internal_field']
    ]
];

$mappedData = $this->tcaMapper->mapRecord(
    'tx_myext_table',
    $record,
    fieldConfiguration: $fieldConfiguration,
    resolveDepth: 1
);
```

### Custom Callbacks

Use `customCallbacks` to handle computed fields or modify values dynamically:

```php
$callbacks = [
    'full_name' => function (array $record, array $mappedRecord) {
        return $record['first_name'] . ' ' . $record['last_name'];
    },
    'dynamic_link' => function (array $record) {
        return 'https://example.com/' . $record['slug'];
    }
];

$mappedData = $this->tcaMapper->mapRecord(
    'tx_myext_table',
    $record,
    customCallbacks: $callbacks
);
```

### Field Renaming

Rename database fields for the API output:

```php
$renamedFields = [
    'tx_myext_legacy_field' => 'new_api_name'
];

$mappedData = $this->tcaMapper->mapRecord(
    'tx_myext_table',
    $record,
    renamedFields: $renamedFields
);
```
