# MCP Integration

`sg_apicore` can expose existing API endpoints as MCP tools without duplicating business logic.

Online extension documentation:

- https://www.sgalinski.de/en/typo3-products-web-development/modern-api-core-for-typo3/

## What This Provides

- Tool discovery (`tools/list`) from existing endpoint metadata.
- Tool execution (`tools/call`) through the existing router, auth, tenant, and validation flow.
- Endpoint-level MCP control via attributes.
- Global and API-level exposure controls (kill switch and denylist).

## Runtime Endpoint

MCP requests are handled via:

- `POST /api/{apiId}/v{version}/mcp`
- `GET /api/{apiId}/v{version}/mcp` for the optional Streamable HTTP server-to-client SSE channel

Supported JSON-RPC methods:

- `initialize`
- `tools/list`
- `tools/call`

The `GET` endpoint returns `Content-Type: text/event-stream`, a slow SSE `retry` hint, and an initial SSE comment. It
does not currently emit server-initiated JSON-RPC requests or notifications, but it keeps clients compatible with MCP
Streamable HTTP clients that probe the optional server-to-client event stream without advertising a long-lived PHP
worker.

## Architecture

The request path is:

1. MCP client sends JSON-RPC request to `/mcp`.
2. `McpController` validates JSON-RPC envelope.
3. `McpToolService` resolves visible tools for `apiId/version`.
4. For `tools/call`, arguments are mapped to path/query/body parameters.
5. Request is dispatched through `Router::dispatch(...)`.
6. Response is normalized into MCP `result` format.

Important: no endpoint business logic is reimplemented in MCP.

## Exposure Model

Tool exposure is resolved in this order:

1. Global kill switch (`mcpEnabled`) in extension configuration.
2. Global API deny list (`mcpDisabledApis`) in extension configuration.
3. API-level MCP settings from `ApiRegistry::registerApi(..., $options)`:
    - `mcpEnabled` (bool, default `true`)
    - `mcpDenylist` (array of endpoint IDs/tool names, exact or wildcard)
4. Endpoint exclusion attribute: `#[ApiMcp(exclude: true)]`
5. Convention-based exclusions:
    - `/docs*`
    - `/demo*`
    - `/internal*`

## Configuration (Extension)

Extension settings (`ext_conf_template.txt`):

- `mcpEnabled` (`1|0`): global kill switch.
- `mcpDisabledApis` (comma-separated): disable MCP for specific API IDs.
- `mcpDenylist` (comma-separated): global denylist entries.

Examples:

- `mcpDisabledApis = public,partner`
- `mcpDenylist = sgai_post_chat_completions,sgai:1:post:/demo/generate-alt-text`

## Configuration (ApiRegistry)

Per API registration:

```php
$apiRegistry->registerApi('sgai', ['1'], [
	'authMode' => 'token',
	'authProviders' => ['beareropaquetokenprovider'],
], NULL, [
	'mcpEnabled' => true,
	'mcpDenylist' => [
		'sgai_post_chat_completions',
		'sgai:1:post:/demo/generate-alt-text',
	],
]);
```

## Endpoint Attribute

Use `ApiMcp` on endpoint methods:

```php
use SGalinski\SgApiCore\Attribute\ApiMcp;

#[ApiMcp(
	exclude: false,
	name: 'sgai_generate_seo_title',
	description: 'Generate an SEO title from provided context',
	notes: 'Client must provide context payload directly.'
)]
```

Attribute options:

- `exclude` (bool): hide endpoint from MCP.
- `name` (string): override generated tool name.
- `description` (string): override generated description.
- `notes` (string): append usage hints to tool description.

## Tool Naming and IDs

Default tool name pattern:

- `{apiId}_{httpMethod}_{pathSlug}`
- Example: `sgai_post_seo_title`

Deterministic endpoint ID:

- `{apiId}:{version}:{method}:{path}`
- Example: `sgai:1:post:/seo/title`

These IDs are used by denylist matching and `api:mcp:list`.

## Supported Parameter Mapping

`tools/call.arguments` are mapped by parameter metadata:

- `ApiPathParam` -> route placeholders (`/messages/{messageId}`)
- `ApiQueryParam` -> query string
- `ApiBodyParam` -> parsed JSON body

Validation stays enforced by `RequestValidator`.

## JSON-RPC Examples

### initialize

```json
{
    "jsonrpc": "2.0",
    "id": "init-1",
    "method": "initialize",
    "params": {}
}
```

### tools/list

```json
{
    "jsonrpc": "2.0",
    "id": "tools-1",
    "method": "tools/list",
    "params": {}
}
```

### tools/call

```json
{
    "jsonrpc": "2.0",
    "id": "call-1",
    "method": "tools/call",
    "params": {
        "name": "sgai_generate_seo_title",
        "arguments": {
            "context": "<h1>About us</h1><p>TYPO3 + AI experts</p>",
            "language": "en"
        }
    }
}
```

Successful tool calls return the endpoint payload in two places:

- `content[0].text`: a bounded JSON/text representation for clients that only display MCP text content.
- `structuredContent`: the same payload as structured JSON for clients that consume typed tool output.

Large string values are truncated only in `content[0].text`; `structuredContent` keeps the complete endpoint payload.

## cURL Examples

Set variables once:

```bash
MCP_URL="https://<host>/api/<apiId>/v<version>/mcp"
MCP_TOKEN="<token>"
```

For APIs using `authMode: public`, the `Authorization` header is optional.

### initialize

```bash
curl -sS -X POST "$MCP_URL" \
  -H "Authorization: Bearer $MCP_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":"init-1","method":"initialize","params":{}}' | jq
```

### tools/list

```bash
curl -sS -X POST "$MCP_URL" \
  -H "Authorization: Bearer $MCP_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":"tools-1","method":"tools/list","params":{}}' | jq
```

### tools/call

```bash
curl -sS -X POST "$MCP_URL" \
  -H "Authorization: Bearer $MCP_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "jsonrpc":"2.0",
    "id":"call-1",
    "method":"tools/call",
    "params":{
      "name":"sgai_generate_seo_title",
      "arguments":{
        "context":"<h1>About us</h1><p>TYPO3 + AI experts</p>",
        "language":"en"
      }
    }
  }' | jq
```

### Open optional SSE stream (`GET /mcp`)

```bash
curl -i -N "$MCP_URL" \
  -H "Authorization: Bearer $MCP_TOKEN" \
  -H "Accept: text/event-stream"
```

Important: if `Accept: text/event-stream` is missing, the endpoint returns `406 Not Acceptable`.

## Client Integration Patterns

Most MCP clients can connect with:

- transport: HTTP
- URL: `https://<host>/api/{apiId}/v{version}/mcp`
- header: `Authorization: Bearer <token>`

If a client supports only `stdio`, use a small bridge/proxy that forwards stdio MCP messages to the HTTP endpoint.

## Implementation Hints (Codex, Claude, Others)

Use this baseline checklist for MCP client onboarding:

1. Verify the endpoint with `initialize` and `tools/list` via `curl` before touching client config.
2. Start with one dedicated API token and minimal scopes.
3. Confirm `authMode` of the target API:
    - `public`: no `Authorization` header required.
    - `token` or `user`: send `Authorization: Bearer <token>`.
4. For optional server-to-client stream probing, ensure `Accept: text/event-stream` on `GET /mcp`.
5. If tools are missing, check denylist/exclude rules with `vendor/bin/typo3 api:mcp:list --json`.

### Codex

- Use MCP server URL: `https://<host>/api/<apiId>/v<version>/mcp`
- Prefer `bearer_token_env_var` in config instead of hardcoding tokens.
- Validate registration by calling `tools/list` from the client and comparing with `api:mcp:list --json`.

### Claude Desktop

- Configure transport as HTTP and use the same MCP URL.
- Add the bearer token header when the API is not `public`.
- If connection testing fails, manually verify with the `curl` examples in this document first.

### Cursor / Other MCP Clients

- Reuse the same URL and auth model.
- Ensure JSON-RPC requests use `"jsonrpc": "2.0"` and object-style `params.arguments`.
- Treat `406` on `GET /mcp` as an Accept-header issue, not as endpoint unavailability.

### Junie Example

```json
{
    "name": "my-api",
    "transport": "http",
    "url": "https://<host>/api/<apiId>/v<version>/mcp",
    "headers": {
        "Authorization": "Bearer <token>"
    }
}
```

### Cursor Example

```json
{
    "mcpServers": {
        "my-api": {
            "transport": "http",
            "url": "https://<host>/api/<apiId>/v<version>/mcp",
            "headers": {
                "Authorization": "Bearer <token>"
            }
        }
    }
}
```

### Codex Example

```toml
[mcp_servers.my-api]
url = "https://<host>/api/<apiId>/v<version>/mcp"
bearer_token_env_var = "MY_API_TOKEN"
```

### Concrete SG AI Production Example

Use this endpoint:

- `https://ai.sgalinski.de/api/sgai/v1/mcp`

Example (Codex TOML style):

```toml
[mcp_servers.sgai]
url = "https://ai.sgalinski.de/api/sgai/v1/mcp"
bearer_token_env_var = "SGAI_API_TOKEN"
```

## Operational Commands

Preview exposed tools:

```bash
vendor/bin/typo3 api:mcp:list
vendor/bin/typo3 api:mcp:list --api=sgai --api-version=1
vendor/bin/typo3 api:mcp:list --json
```

Output includes:

- API ID
- Version
- Tool name
- HTTP method
- Endpoint path
- Deterministic endpoint ID

## Security Notes

- MCP does not bypass `sg_apicore` authentication or scope checks.
- Keep `mcpEnabled` as emergency kill switch for incident response.
- Use denylist and `ApiMcp(exclude: true)` for sensitive endpoints.
- Do not expose demo/internal endpoints in production.
- Keep log redaction enabled for sensitive fields.

## Troubleshooting

### `tools/list` returns no tools

- Check global kill switch (`mcpEnabled`).
- Check API is registered and version exists.
- Check `mcpDisabledApis`.
- Check denylist and endpoint exclusions.
- Verify with `api:mcp:list --json`.

### `tools/call` returns "Tool not found"

- Name mismatch (client request name not equal to exposed tool name).
- Tool filtered by denylist/exclude rules.
- API/version mismatch in URL.

### `tools/call` returns validation errors

- Missing required path/body/query arguments.
- Wrong type (for example non-scalar for path params).
- Failing endpoint-level constraints (`required`, `pattern`, `min/max`).

### `tools/call` returns auth errors

- Missing/invalid bearer token.
- Wrong API auth mode/provider configuration.
- Missing required scopes/user context for endpoint.
