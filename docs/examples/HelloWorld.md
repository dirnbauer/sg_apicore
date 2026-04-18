# Example: Hello World API

This guide shows a minimal but current `sg_apicore` setup with route metadata and parameter validation.

## 1. Register an API

In your extension `ext_localconf.php`:

```php
use SGalinski\SgApiCore\Service\ApiRegistry;
use TYPO3\CMS\Core\Utility\GeneralUtility;

(static function (): void {
	$apiRegistry = GeneralUtility::makeInstance(ApiRegistry::class);
	$apiRegistry->registerApi('hello', ['1'], [
		'authMode' => 'public',
	]);
})();
```

## 2. Create a controller endpoint

Create `Classes/Controller/HelloController.php`:

```php
namespace MyVendor\MyApi\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SGalinski\SgApiCore\Attribute\ApiEndpoint;
use SGalinski\SgApiCore\Attribute\ApiQueryParam;
use SGalinski\SgApiCore\Attribute\ApiResponse;
use SGalinski\SgApiCore\Attribute\ApiRoute;
use SGalinski\SgApiCore\Service\ResponseService;

class HelloController {
	public function __construct(
		protected ResponseService $responseService
	) {
	}

	#[ApiRoute(path: '/world', methods: ['GET'], apiId: 'hello', version: '1')]
	#[ApiEndpoint(summary: 'Hello world endpoint', tags: ['Hello'])]
	#[ApiQueryParam(name: 'name', type: 'string', required: FALSE, minLength: 2, maxLength: 60, example: 'TYPO3')]
	#[ApiResponse(status: 200, description: 'Greeting response')]
	public function worldAction(ServerRequestInterface $request): ResponseInterface {
		$queryParams = $request->getQueryParams();
		$name = trim((string) ($queryParams['name'] ?? 'World'));

		return $this->responseService->createSuccessResponse([
			'message' => 'Hello ' . $name . '!',
		]);
	}
}
```

## 3. Register the controller service

In `Configuration/Services.php`:

```php
$services->set(\MyVendor\MyApi\Controller\HelloController::class)
	->tag('sg_apicore.router');
```

## 4. Test the endpoint

```bash
curl "https://your-instance.local/api/hello/v1/world?name=TYPO3"
```

Expected response:

```json
{
  "message": "Hello TYPO3!"
}
```

## 5. Use the full template

For a larger real-world template (auth scopes, pagination, body validation, cache and TypoScript requirements), use:

- `docs/examples/ExampleController.php`
