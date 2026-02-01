# APIs & Registration

`sg_apicore` supports multiple APIs in parallel, each of which can have its own versions and security configurations.

## API Registration

Registration takes place in your extension's `ext_localconf.php` via the `ApiRegistry` service.

```php
use SGalinski\SgApiCore\Service\ApiRegistry;
use TYPO3\CMS\Core\Utility\GeneralUtility;

$apiRegistry = GeneralUtility::makeInstance(ApiRegistry::class);

// Registers a public API
$apiRegistry->registerApi('public', ['1'], [
    'authMode' => 'public'
]);

// Registers a partner API with token authentication and rate limits
$apiRegistry->registerApi(
	'partner',
	['1', '2'],
	[
		'authMode' => 'token'
	],
	NULL,
	[
		'rateLimit' => [
			'limit' => 120,
			'windowSeconds' => 60,
			'burst' => 30
		]
	]
);
```

## Configuration Options

When registering, the following options can be passed in the third parameter:

* `authMode`: Defines the default authentication mode for all endpoints of this API.
    * `public`: No token required (unless explicitly required via attribute).
    * `token`: A valid Opaque Bearer Token is required.
    * `user`: A user login (Access/Refresh Token) is required.
* `authProviders`: List of allowed providers (e.g., `['beareropaquetokenprovider']`).
  Use the fourth parameter to override the base path (default: `/api/{apiId}/v{version}`).

Use the fifth parameter for additional options:

* `rateLimit`: Overrides rate limit settings for this API (see `RateLimiting.md`).

## Versioning

The version is specified in the URL with the prefix `v`, e.g., `/api/public/v1/...`. The `ApiRegistry` checks whether
the requested version is enabled for the respective API ID.
