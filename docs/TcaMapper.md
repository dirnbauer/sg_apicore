# TCA Mapper

The `TcaMapper` service allows TYPO3 database records (arrays) to be automatically converted into API-compliant
structures. It uses the information from the Table Configuration Array (TCA).

## Features

* **Automatic Whitelisting**: Internal TYPO3 fields (such as `tstamp`, `crdate`, `hidden`) are excluded by default.
* **Type Conversion**:
    * Booleans (for `type => check`)
    * Integers (for `eval => int` or `type => number`)
    * Date formats (ISO 8601 strings for `inputDateTime`)
* **Multi-Value Support**: Comma-separated lists (e.g., relations) are automatically converted into arrays.

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
