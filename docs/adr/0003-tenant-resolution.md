# ADR 0003: Tenant Resolution

## Status

Accepted

## Context

The API must function in a TYPO3 instance with multiple websites (multi-site). Each request must be assigned to a tenant
to apply site-specific data and security rules (e.g., tokens).

## Decision

We use the **TYPO3 Site** as the default source for the tenant identity.

## Justification

1. **Zero Configuration**: In most TYPO3 projects, a website corresponds to a tenant. Since TYPO3 already assigns the
   request to a site, we can use this information directly.
2. **Security**: Tokens are bound to a `tenantId`. Coupling to the site prevents a token from one website from
   accidentally working for another website in the same instance.
3. **Extensibility**: Further sources (headers, hostnames) can be added via a resolver chain (`TenantResolverChain`) if
   the site logic is insufficient.

## Consequences

If no TYPO3 site can be determined (e.g., due to incorrect domain configuration), the API request is rejected by default
with a 404, as no secure context can be established.
