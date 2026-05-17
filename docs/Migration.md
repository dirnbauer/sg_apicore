# Migration Guide: sg_rest to sg_apicore

This document describes how to migrate existing APIs from the deprecated `sg_rest` extension to the new `sg_apicore`
architecture.
The guide targets the `14.x` release line for TYPO3 `^14.3`.

## 0. Preparation

To enable the backward compatibility features, you must first activate the legacy support in the extension configuration
of `sg_apicore`:

1. Go to **Admin Tools > Extension Configuration**.
2. Select `sg_apicore`.
3. Enable the checkbox **Activate Legacy Support**.
4. Clear all caches.

## 1. Concept Comparison

| Feature  | sg_rest                             | sg_apicore                                 |
|----------|-------------------------------------|--------------------------------------------|
| Routing  | Automatic via `apiKey` and `entity` | Declarative via `#[ApiRoute]` attributes   |
| Auth     | `fe_users` (tx_sgrest_auth_token)   | Opaque Tokens, JWT, Legacy Bridge          |
| Models   | Extbase Models                      | Plain PHP/DTOs or directly via TCA mapping |
| Response | Fixed JSON format                   | Flexible, Default: RFC 7807 (Errors)       |

## 2. Backward Compatibility (Legacy Mode)

`sg_apicore` provides a bridge to continue serving old clients with minimal changes.

### URLs & Routing

The `LegacyRoutingMiddleware` automatically handles old `sg_rest` style requests. It supports two formats:

1. **Query-based**: `/?type=1595576052&tx_sgrest[request]=apiKey/entity/identifier`
2. **Path-based**: `/apiKey/entity/identifier` (Requires `type=1595576052` parameter for precision)

These requests are internally mapped to the new structure:
`/api/legacy/v1/{apiKey}/{entity}/{identifier}`

> **Important**: You must register a `legacy` API in your `ext_localconf.php` to handle these requests. By default, the
`sg_apicore` demo configuration already includes this.

### Token Authentication (Bearer Token)

The old `sg_rest` authentication route `authentication/authentication/getBearerToken` is supported through a special
mapping to `/api/legacy/v1/auth/legacyLogin`.

**Response Format**: It returns the expected `{"bearerToken": "..."}` format. Legacy `authtoken` and `bearertoken`
headers are still detected and mapped into the new authentication pipeline.

### Manual Endpoint Mapping

Since `sg_apicore` uses declarative routing, you must manually map your old endpoints in your controllers.

1. Register the `legacy` API.
2. Create or update your controllers.
3. Add `#[ApiRoute]` with the path matching your old structure *including* the `apiKey`.

**Example**:
Old endpoint: `apiKey/news/list`
New mapping:

```php
#[ApiRoute(path: '/apiKey/news/list', methods: ['GET'])]
public function listAction(...)
```

### User Token Migration

The old `tx_sgrest_auth_token` in `fe_users` is deprecated and no longer supported.
All clients MUST migrate to the new token system.

If you want to log in against a user account, you can use the following steps:

1. Users should authenticate via the new `/api/legacy/v1/auth/legacyLogin` (or `/api/legacy/v1/auth/login`) endpoint.
2. This will issue a new token (Opaque or JWT) stored in the `tx_apicore_token` table.
3. Once all clients are migrated, the `tx_sgrest_auth_token` column can be removed from the `fe_users` table.

### Response Format

Use the `#[ApiLegacyMode]` attribute on your controller or action to emulate the old JSON format
(data wrapping, legacy error format). If your endpoint requires full TypoScript for rendering,
use the `#[RequireFullTypoScript]` attribute as well:

```php
#[ApiLegacyMode(source: 'sg_rest', wrapData: true, legacyErrorFormat: true)]
#[RequireFullTypoScript]
class MyLegacyController {
    // ...
}
```

## 3. Step-by-Step Migration

1. **API Registration**: Register a new API in `ext_localconf.php` using the name of your old API key.
2. **Create Controller**: Create a new controller and use `#[ApiRoute]` to map the old paths.
3. **Data Access**:
    - For simple CRUD operations, use Auto-CRUD resources or the `TcaMapper`.
    - For complex logic, inject your repositories or services into the controller.
4. **Auth**: The `LegacyTokenProvider` was removed. You must issue new tokens via the login endpoints.

## 4. Example: News API Migration (EXT:sg_demo)

In the `sg_demo` extension, a legacy news API was migrated.

**Old Controller (sg_rest):**

- Path: `news/news/list`
- Authenticated via `api.auth` request attribute (replaces `AuthenticationServiceInterface`).

**Migrated Controller (sg_apicore):**

```php
namespace SGalinski\SgDemo\Controller\Rest\News;

use SGalinski\SgApiCore\Attribute\ApiRoute;
use SGalinski\SgApiCore\Attribute\ApiLegacyMode;
use SGalinski\SgApiCore\Attribute\RequireUser;

class NewsController {
    #[ApiRoute(path: '/news/news/list', methods: ['GET'], apiId: 'legacy')]
    #[ApiLegacyMode]
    #[RequireUser]
    public function getListAction($request) {
        // ... (use NewsRepository to fetch data)
        return $this->responseService->createSuccessResponse($response);
    }
}
```

The `LegacyRoutingMiddleware` handles the incoming request:

1. Client calls `/?type=1595576052&tx_sgrest[request]=news/news/list`.
2. Middleware maps this to `/api/legacy/v1/news/news/list`.
3. Router matches the route `/news/news/list` for the `legacy` API.
4. `#[ApiLegacyMode]` ensures the response format matches what the client expects.
5. `#[RequireUser]` ensures the client must provide a valid (legacy) token.
