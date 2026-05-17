# APIs & Registration

`sg_apicore` supports multiple APIs in parallel, each of which can have its own versions and security configurations.
This guide targets the `14.x` release line for TYPO3 `^14.3` and PHP `^8.3`.

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

* `authMode` (**string**): Defines the default authentication mode for all endpoints of this API.
    * `public`: No token required (unless explicitly required via attribute).
    * `token`: A valid Opaque Bearer Token is required.
    * `user`: A user login (Access/Refresh Token) is required.
    * `backend`: A valid TYPO3 backend user session is required.
* `authProviders`: List of allowed providers (e.g., `['beareropaquetokenprovider', 'backenduserprovider']`).
* `basePath`: Optional path override in the fourth argument. The default is `/api/{apiId}/v{version}`.
* `rateLimit`: Optional rate-limit override in the fifth argument (see [Rate Limiting](RateLimiting.md)).

### Backend API Example

For internal APIs that should only be accessible to logged-in TYPO3 backend users, you can use the `backend` authMode:

```php
$apiRegistry->registerApi('backend', ['1'], [
	'authMode' => 'backend',
	'authProviders' => ['backenduserprovider'],
]);
```

This configuration ensures that the API is only accessible if a valid backend user session exists. The
`backenduserprovider` automatically resolves the user and provides standard scopes like `backend`, `partner:read`,
`partner:write`, and `user`.

### Endpoint Overrides

While the API-level `authMode` must be a string, individual endpoints can define multiple modes using an array in the
`#[ApiRoute]` attribute:

```php
// Available via public access OR with a valid user token
#[ApiRoute(path: '/login', methods: ['POST'], authMode: ['public', 'user'])]
```

## Versioning

The version is specified in the URL with the prefix `v`, e.g., `/api/public/v1/...`. The `ApiRegistry` checks whether
the requested version is enabled for the respective API ID.
