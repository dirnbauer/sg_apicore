# Authentication & Scopes

`sg_apicore` provides a flexible system for authentication and authorization.

## Authentication Modes

The mode is defined per API in the `ApiRegistry` (see [APIs.md](APIs.md)).

1. **Public**: No authentication required.
2. **Token (Opaque Bearer)**: Requires an API key in the header: `Authorization: Bearer <token>`.
3. **User**: Requires a user login. Supports access and refresh tokens.

## Scopes (Permissions)

Scopes are used to control access to specific endpoints in a fine-grained manner. A token can have a list of scopes.

### Enforcing Scopes

Use the `#[RequireScopes]` attribute on your controller method:

```php
use SGalinski\SgApiCore\Attribute\RequireScopes;

#[RequireScopes(['partner:read', 'partner:write'])]
public function updateAction(...) {
    // This method will only be executed if the token possesses BOTH scopes.
}
```

## User Authentication

If the `user` mode is active, a TYPO3 frontend user can authenticate via the login endpoint:

* **URL**: `POST /api/{apiId}/v1/auth/login`
* **Body**: `{"username": "...", "password": "..."}`
* **Response**: Contains `access_token`, `refresh_token`, `token_type`, `expires_in`.

### User Storage and Site Awareness

By default, users are searched for in the current **TYPO3 Site Root**. You can customize the storage pages (PIDs) in
several ways:

1. **sg_account Integration**: If `sg_account` is installed, it uses the storage defined in the Main Configuration.
2. **Site Configuration**: Define storage PIDs in your `site.yaml`:
   ```yaml
   apicore:
     userStoragePids: "123,456"
   ```
3. **Fallback**: Falls back to the root page ID of the current site.

### RequireUser Attribute

To ensure that a request comes from a real user (and not just an M2M token), use:

```php
use SGalinski\SgApiCore\Attribute\RequireUser;

#[RequireUser]
public function profileAction(...) {
    // Only accessible to logged-in frontend users.
}
```

## Authentication Error Responses

Authentication failures return RFC 7807 Problem JSON, include `requestId` for tracing, and set `X-Request-ID`.
If rate limiting is applied, use `X-RateLimit-Limit`, `X-RateLimit-Remaining`, and `X-RateLimit-Reset` headers and
optionally provide a `rateLimit` object in the response body.

* **401 Unauthorized**: Missing credentials (`Authentication required.`).
* **401 Unauthorized**: Invalid or expired token (`Invalid or expired token.`).
* **403 Forbidden**: Authenticated but missing required scopes or `#[RequireUser]`.

## Rate Limit Configuration

Use the extension configuration to enable and tune rate limits:

* `rateLimitEnabled` (boolean)
* `rateLimitDefaultLimit` (int, requests per window)
* `rateLimitWindowSeconds` (int, window size)

See also: `docs/RateLimiting.md`.

## Authentication Error Responses

Authentication failures return RFC 7807 Problem JSON, include `requestId` for tracing, and set `X-Request-ID`.
If rate limiting is applied, use `X-RateLimit-Limit`, `X-RateLimit-Remaining`, and `X-RateLimit-Reset` headers and
optionally provide a `rateLimit` object in the response body.

* **401 Unauthorized**: Missing credentials (`Authentication required.`).
* **401 Unauthorized**: Invalid or expired token (`Invalid or expired token.`).
* **403 Forbidden**: Authenticated but missing required scopes or `#[RequireUser]`.

## Token Management in the Backend

In the TYPO3 backend under **System > API Core**, you can:

* Create new Opaque tokens (M2M).
* View and revoke existing tokens.
* Manage scopes and expiration dates.
