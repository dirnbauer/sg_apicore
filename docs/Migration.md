# Migration Guide: sg_rest to sg_apicore

This document describes how to migrate existing APIs from the deprecated `sg_rest` extension to the new `sg_apicore`
architecture.

## 1. Concept Comparison

| Feature  | sg_rest                             | sg_apicore                                 |
|----------|-------------------------------------|--------------------------------------------|
| Routing  | Automatic via `apiKey` and `entity` | Declarative via `#[ApiRoute]` attributes   |
| Auth     | `fe_users` (tx_sgrest_auth_token)   | Opaque Tokens, JWT, Legacy Bridge          |
| Models   | Extbase Models                      | Plain PHP/DTOs or directly via TCA mapping |
| Response | Fixed JSON format                   | Flexible, Default: RFC 7807 (Errors)       |

## 2. Backward Compatibility (Legacy Mode)

`sg_apicore` provides a bridge to continue serving old clients without URL changes.

### URLs

The `LegacyRoutingMiddleware` detects paths in the format `/{apiKey}/{entity}/{identifier}/{verb}` and internally
redirects them to the new structure `/api/{apiId}/v1/{entity}/...`. By default, the `apiKey` is used as the `apiId`.

### Authentication

Add the `LegacyTokenProvider` to your API configuration to continue accepting tokens from the `fe_users` table:

```php
// ext_localconf.php
$apiRegistry->registerApi('my-legacy-api', [
    'versions' => ['1'],
    'security' => [
        'authProviders' => [
            \SGalinski\SgApiCore\Security\LegacyTokenProvider::class
        ]
    ]
]);
```

### Response Format

Use the `#[ApiLegacyMode]` attribute on your controller or action to emulate the old JSON format (
data wrapping, legacy error format):

```php
#[ApiLegacyMode(source: 'sg_rest', wrapData: true, legacyErrorFormat: true)]
class MyLegacyController {
    // ...
}
```

## 3. Step-by-Step Migration

1. **API Registration**: Register a new API in `ext_localconf.php` using the name of your old API key.
2. **Create Controller**: Create a new controller and use `#[ApiRoute]` to map the old paths.
3. **Data Access**:
    - For simple CRUD operations, use **TCA Mapping** (Phase K).
    - For complex logic, inject your repositories or services into the controller.
4. **Auth**: If you don't want to issue new tokens, use the `LegacyTokenProvider`.

## 4. Example: News API Migration

**Old (sg_rest):**
URL: `/api-key-123/news/list`

**New (sg_apicore):**

```php
namespace MyVendor\MyExt\Controller;

use SGalinski\SgApiCore\Attribute\ApiRoute;
use SGalinski\SgApiCore\Attribute\ApiLegacyMode;

#[ApiLegacyMode]
class NewsController {
    #[ApiRoute(path: '/news/list', methods: ['GET'])]
    public function listAction($request) {
        // Logic here
    }
}
```

The middleware ensures that a call to `/api-key-123/news/list` is internally mapped to this controller (
provided the API `api-key-123` is registered).
