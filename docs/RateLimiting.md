# Rate Limiting

`sg_apicore` provides a fixed window rate limiter backed by a database table.

## Configuration

Enable and tune limits via extension configuration:

* `rateLimitEnabled` (boolean)
* `rateLimitDefaultLimit` (int, requests per window)
* `rateLimitWindowSeconds` (int, window size)

Default example:

* 60 requests per 60 seconds

## Key Strategy

Limits are applied per `apiId`, `tenantId`, and subject:

* `token:{tokenUid}` if a token is present
* `user:{userId}` if a user token is present
* `ip:{clientIp}` as fallback

## Headers and Response

When enabled, responses include:

* `X-RateLimit-Limit`
* `X-RateLimit-Remaining`
* `X-RateLimit-Reset`

If the limit is exceeded, the API returns `429 Too Many Requests` with Problem JSON and a `rateLimit` object.
