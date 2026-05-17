# ADR 0002: Scope Merge Rules

## Status

Accepted for the TYPO3 `14.x` release line.

## Context

When multiple authentication providers are active (e.g., Bearer Token + User Login), it must be decided how the
respective permissions (scopes) are combined.

## Decision

We use a **Union Strategy** for the scopes of the different identities within a request.

## Justification

1. **Flexibility**: A user can have basic permissions via their login, while a specific API token contributes additional
   technical scopes for the integration.
2. **Simplicity**: An intersection logic would often lead to unexpected errors if providers have different "areas of
   responsibility."
3. **Transparency**: The `AuthContext` aggregates all found scopes. The `#[RequireScopes]` attribute then checks against
   this total list.

## Consequences

Developers must ensure that scopes are uniquely named (e.g., with prefixes like `user:` or `system:`) to avoid unwanted
privilege escalation.
