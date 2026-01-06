# OpenAPI Documentation

`sg_apicore` automatically generates OpenAPI 3.0 specifications based on the attributes in your controllers.

## Browser Access

Every registered API provides endpoints for documentation:

* **JSON Specification**: `/api/{apiId}/v{version}/docs.json`
* **Swagger UI**: `/api/{apiId}/v{version}/docs/ui`

Example: `https://your-website.com/api/public/v1/docs/ui`

## Metadata Attributes

To make the specification meaningful, use the following attributes on your controller methods:

* `#[ApiEndpoint]`: Summary, description, and tags.
* `#[ApiQueryParam]`: Describes a query parameter.
* `#[ApiBodyParam]`: Describes fields in the JSON body.
* `#[ApiPathParam]`: Describes a parameter in the URL path.
* `#[ApiResponse]`: Describes possible responses (status code, description, schema).

## CLI Export

You can also export the specification to a file via the command line:

```bash
vendor/bin/typo3 api:openapi:generate --api=public --api-version=1 --out=openapi.json
```

## Security Schemes

The generated specification automatically includes a `bearerAuth` scheme. If an API does not run in `public` mode, this
scheme is marked as required globally for all endpoints.
