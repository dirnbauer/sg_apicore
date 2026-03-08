# Authentication & Scopes

`sg_apicore` provides a flexible system for authentication and authorization.

## Authentication Modes

The default mode is defined per API in the `ApiRegistry` as a **string** (see [APIs.md](APIs.md)). Individual endpoints
can override or extend this using the `#[ApiRoute]` attribute (supporting both **string** and **array**).

1. **Public**: No authentication required.
2. **Token (Opaque Bearer)**: Requires an API key in the header: `Authorization: Bearer <token>`.
3. **User**: Requires a user login. Supports access and refresh tokens.

Example for an endpoint that is both public and user-accessible:

```php
#[ApiRoute(path: '/auth/login', methods: ['POST'], authMode: ['public', 'user'])]
```

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

## Events

The extension provides PSR-14 events to hook into various processes.

### AfterUserAuthenticationEvent

This event is triggered after a user has successfully authenticated (e.g., password check passed), but before tokens are
generated and the response is sent. It allows you to perform additional checks (like account expiration) and block the
login.

* **Payload**: `getUser()` (array), `getTenantContext()` (?TenantContext).
* **Blocking Login**: Throw an `SGalinski\SgApiCore\Exception\AuthenticationException` within your listener to abort the
  login process with a custom message.

**Example Listener:**

```php
public function __invoke(AfterUserAuthenticationEvent $event): void {
    $user = $event->getUser();
    if ($user['is_blocked']) {
        throw new AuthenticationException('Your account is blocked.');
    }
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
* Optionally bind tokens to a specific FE user (`fe_users.uid` via `user_id`).
* View and revoke existing tokens.
* Manage scopes and expiration dates.
* Keep and reuse the current token filters while performing token actions.
