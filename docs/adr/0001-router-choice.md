# ADR 0001: Router Choice

## Status

Accepted

## Context

A powerful and flexible routing system was required for the API framework. Although TYPO3 offers its own routing
system (Site Configuration & Enhancers), it is tightly coupled to the page tree and frontend processing.

## Decision

We decided to integrate `nikic/fast-route`.

## Justification

1. **Performance**: FastRoute is extremely fast and independent of the complex TYPO3 page tree resolution.
2. **Decoupling**: The API layer remains lean and is not influenced by frontend-specific logics or redirects.
3. **Flexibility**: Using PHP attributes allows endpoints to be defined directly in the controller, enabling a modern
   and developer-friendly workflow (similar to Symfony).
4. **Multi-API & Versioning**: Complex patterns like `/api/{apiId}/v{version}/...` can be implemented without extensive
   enhancer configurations.

## Consequences

Requests to `/api/` (configurable) are intercepted early by a middleware and passed directly to the FastRoute dispatcher
before the TYPO3 frontend bootstrap fully takes effect.
