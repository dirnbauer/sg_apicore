# OpenAPI Documentation

`sg_apicore` automatically generates OpenAPI 3.0 specifications based on the attributes in your controllers.

## Browser Access

Every registered API provides endpoints for documentation:

* **JSON Specification**: `/api/{apiId}/v{version}/docs.json`
* **Swagger UI**: `/api/{apiId}/v{version}/docs/ui`

Example: `https://your-website.com/api/public/v1/docs/ui`

## Auto-CRUD Query Parameters

Auto-CRUD resource endpoints expose their list filter as a query object using the shape `filter[field]=value`.

Example:

```text
/api/public/v1/pages?filter[title]=Example title
```

In the generated OpenAPI specification this parameter is modeled as an object-style query parameter (`deepObject`), not
as a JSON request body. Swagger UI should therefore show `filter` as a structured query parameter with the allowed
fields from the resource configuration.

## Metadata Attributes

To make the specification meaningful, use the following attributes on your controller methods:

* `#[ApiEndpoint]`: Summary, description, and tags.
* `#[ApiQueryParam]`: Describes a query parameter.
* `#[ApiBodyParam]`: Describes fields in the JSON body.
* `#[ApiPathParam]`: Describes a parameter in the URL path.
* `#[ApiResponse]`: Describes possible responses (status code, description, schema).

### Global Schemata

You can define global schemas that can be reused across multiple endpoints. This is useful for complex objects like "
Offer" or "User".

```php
use SGalinski\SgApiCore\Service\SchemaRegistry;
use TYPO3\CMS\Core\Utility\GeneralUtility;

$schemaRegistry = GeneralUtility::makeInstance(SchemaRegistry::class);
$schemaRegistry->registerSchema('public', 'MyObject', [
    'type' => 'object',
    'properties' => [
        'id' => ['type' => 'integer'],
        'name' => ['type' => 'string']
    ]
], 'tx_my_table'); // Optional: Map to TCA table for enrichment
```

Referencing a schema in an attribute:

```php
#[ApiResponse(status: 200, schema: 'MyObject')]
#[ApiResponse(status: 200, schema: 'MyObject[]')] // Array of objects
```

### TCA Enrichment

`sg_apicore` automatically enriches schemas with labels from the TYPO3 TCA.

1. **Global Schemas**: If a `$tableName` is provided during `registerSchema()`, all properties in the schema that match
   TCA columns will automatically receive the translated `label` as their OpenAPI `description`. This
   works recursively for nested objects with `foreign_table` relations.
2. **Ad-hoc Schemas**: If you provide a `schema` name (table name) in the `#[ApiResponse]` attribute without a global
   definition, it will also be enriched.
3. **Merging with Examples**: When combining a `schema` (reference or table) with an `example`, the generator merges the
   structures. Properties from the schema are preserved, and example values are used to infer types or provide sample
   data.
4. **Remapped Fields**: If a property in your schema does not match the TCA column name (e.g., it was remapped in your
   API), you can add a custom `x-tca-field` property to the schema property definition to specify the original TCA
   column name.

   Example:
   ```php
   $schemaRegistry->registerSchema('partner', 'Offer', [
       'type' => 'object',
       'properties' => [
           'tags' => [
               'type' => 'array',
               'x-tca-field' => 'offertags', // Original TCA column name
               'items' => [...]
           ]
       ]
   ], 'tx_citypower_domain_model_offer');
   ```

### Automatic Schema Generation

`sg_apicore` can automatically generate schemas from your example data. If you provide an `example` in the
`#[ApiResponse]` attribute, the service will recursively build an OpenAPI schema based on its structure and data types.

If you also provide a `schema` name (usually a table name or a DTO class name) in the `#[ApiResponse]` attribute, the
generator will attempt to enrich the schema with descriptions from the corresponding TCA labels.

Example:

```php
#[ApiResponse(status: 200, description: 'Success response', schema: 'tx_my_table', example: [
    'title' => 'Sample Title',
    'child' => [
        'name' => 'Sub-item'
    ]
])]
```

In this case, the generator will check `tx_my_table` for the `title` label and use it as a description. For the nested
`child` object, it will look at the `foreign_table` configuration in the TCA of `tx_my_table` to resolve labels for the
`child`'s properties.

### Schema Placeholders in Examples

If you provide an `example` in the `#[ApiResponse]` attribute, you can use placeholders to reference global schemas
within your example structure. This is extremely useful for paginated responses where you want to show the full
structure
of the items without repeating the schema definition.

The format is `schema:SchemaName` or `schema:SchemaName[]` (for an array).

Example:

```php
#[ApiResponse(status: 200, description: 'List of offers', schema: 'Offer[]', example: [
    'data' => 'schema:Offer[]',
    'meta' => [
        'total' => 100,
        'page' => 1
    ]
])]
```

The generator will automatically replace the placeholder with a generated stub based on the "Offer" schema's properties
and their example values or types.

## CLI Export

You can also export the specification to a file via the command line:

```bash
vendor/bin/typo3 api:openapi:generate --api=public --api-version=1 --out=openapi.json
```

## Security Schemes

The generated specification automatically includes a `bearerAuth` scheme. If an API does not run in `public` mode, this
scheme is marked as required globally for all endpoints.
