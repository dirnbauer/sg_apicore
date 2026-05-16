# sg_apicore for TYPO3

`sg_apicore` is a high-performance API framework for TYPO3 projects.  
It enables teams to build secure, documented, and maintainable APIs directly in TYPO3 without adding a separate API platform.

This text is designed for website publication and product communication.

Current version line: `14.x` is TYPO3 v14-only and targets TYPO3 `^14.3` with PHP `^8.3`. TYPO3 13 support is not
maintained in this release line.

## What sg_apicore Solves

Modern TYPO3 projects often need more than page rendering:

- headless frontends and apps
- partner portals and data integrations
- mobile apps and authenticated user APIs
- high-throughput machine-to-machine APIs

`sg_apicore` provides one consistent framework for these use cases, including routing, authentication, scopes, OpenAPI docs, caching, and observability.

## Core Benefits

- Fast API routing based on PHP attributes
- Multiple APIs and versions in parallel
- Secure token and user authentication modes
- Fine-grained access control with scopes
- Automatic OpenAPI and Swagger UI generation
- Built-in response caching and cache invalidation
- Optional multi-tenant operation (site-based by default)
- TYPO3 backend module for API and token operations
- Legacy migration bridge for old `sg_rest` setups

## Typical Use Cases

- Public read-only content APIs
- Partner APIs with scope-based access
- User APIs with login, access token, and refresh token
- BFF endpoints for SPAs and native apps
- Controlled CRUD endpoints for TYPO3 tables

## Authentication and Security

`sg_apicore` supports multiple authentication models:

- `public`: no token required
- `token`: machine token (opaque bearer)
- `user`: user login with access and refresh token
- `backend`: backend session based endpoints (internal use cases)

Security features include:

- Scope enforcement per endpoint
- Optional `RequireUser` checks for true user context
- RFC 7807 compliant error responses
- Request IDs for tracing and support
- Configurable key redaction in logs
- Configurable rate limiting

## API Documentation for Consumers

Every registered API can expose:

- JSON specification: `/api/{apiId}/v{version}/docs.json`
- Swagger UI: `/api/{apiId}/v{version}/docs/ui`

This lets internal and external consumers test endpoints, inspect schemas, and integrate faster.

## Operations and Performance

`sg_apicore` is built for production workloads:

- Response caching for GET requests
- Cache control and tag-based invalidation
- Optional language and user-group aware cache variation
- Rate-limit headers for client-side handling
- Dedicated backend dashboard for API visibility

## Backend Module Highlights

In TYPO3 backend under `System > API Core`, teams can:

- inspect registered APIs and endpoints
- create and revoke API tokens
- manage token scopes and expiry
- inspect endpoint requirements
- monitor rate limits and logs

## Multi-Tenant Readiness

Each request is processed in a tenant context.  
By default, tenant resolution is site-aware and can be extended through resolver chains.

This allows one TYPO3 instance to provide separated API behavior for multiple sites or clients.

## Why Teams Choose sg_apicore

- Uses TYPO3-native concepts instead of a disconnected external stack
- Clear and auditable endpoint definitions via attributes
- Good developer experience with generated API docs
- Reduced integration risks through standardized responses and validation
- Proven in active production projects with sustained load

## Recommended Rollout Steps

1. Define your API IDs and versions.
2. Select auth modes per API (`public`, `token`, `user`, `backend`).
3. Configure scopes and rate limits.
4. Enable OpenAPI endpoints for internal and partner onboarding.
5. Add caching rules for high-traffic read endpoints.
6. Use backend token management for secure key lifecycle.

## Further Technical Documentation

For implementation details, see:

- `docs/APIs.md`
- `docs/WritingEndpoints.md`
- `docs/AuthScopes.md`
- `docs/OpenAPI.md`
- `docs/RateLimiting.md`
- `docs/Tenants.md`
