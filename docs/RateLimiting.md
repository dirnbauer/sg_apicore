# Rate Limiting

`sg_apicore` provides a fixed window rate limiter backed by a database table.

## Global Configuration

Enable and tune limits via extension configuration:

* `rateLimitEnabled` (boolean)
* `rateLimitDefaultLimit` (int, requests per window)
* `rateLimitWindowSeconds` (int, window size)
* `rateLimitDefaultBurst` (int, additional requests per window)

Default example:

* 60 requests per 60 seconds

## Overrides

Rate limits can be overridden per API or per Auto-CRUD resource.

### API-level overrides

```php
$apiRegistry->registerApi(
	'partner',
	['1'],
	['authMode' => 'token'],
	NULL,
	[
		'rateLimit' => [
			'enabled' => TRUE,
			'limit' => 120,
			'windowSeconds' => 60,
			'burst' => 30
		]
	]
);
```

You can also override by version:

```php
[
	'rateLimit' => [
		'enabled' => TRUE,
		'limit' => 60,
		'windowSeconds' => 60,
		'versions' => [
			'2' => [
				'enabled' => TRUE,
				'limit' => 200,
				'windowSeconds' => 60
			]
		]
	]
]
```

### Resource-level overrides

```php
$resourceRegistry->registerResource('partner', 'tx_myext_domain_model_item', '/items', [
	'rateLimit' => [
		'enabled' => TRUE,
		'limit' => 30,
		'windowSeconds' => 60,
		'burst' => 10
	]
]);
```

Resource-level configuration takes precedence over API-level configuration.
Set `enabled` to `FALSE` to disable rate limiting for a specific API or resource.

## Key Strategy

Limits are applied per `apiId`, `tenantId`, and subject:

* `token:{tokenUid}` if a token is present
* `user:{userId}` if a user token is present
* `ip:{clientIp}` as fallback

## Headers and Response

When enabled, responses include:

* `X-RateLimit-Limit` (effective limit including burst)
* `X-RateLimit-Remaining`
* `X-RateLimit-Reset`
* `X-RateLimit-Burst` (only if burst > 0)

If the limit is exceeded, the API returns `429 Too Many Requests` with Problem JSON and a `rateLimit` object.

## Cleanup

Rate limit counters are registered with TYPO3's table garbage collection task and are removed 30 days after
`expires_at` by default.
