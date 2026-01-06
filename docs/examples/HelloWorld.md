# Example: Hello World API

To create a new API in TYPO3 using `sg_apicore`, follow these steps:

## 1. Extension Setup

Create a TYPO3 extension (e.g., `my_api`).

## 2. API Registration

Register the API in your `ext_localconf.php`:

```php
use SGalinski\SgApiCore\Service\ApiRegistry;
use TYPO3\CMS\Core\Utility\GeneralUtility;

$apiRegistry = GeneralUtility::makeInstance(ApiRegistry::class);
$apiRegistry->registerApi('hello', ['1'], ['authMode' => 'public']);
```

## 3. Create a Controller

Create the file `Classes/Controller/HelloController.php`:

```php
namespace MyVendor\MyApi\Controller;

use SGalinski\SgApiCore\Attribute\ApiRoute;
use SGalinski\SgApiCore\Service\ResponseService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class HelloController {
    public function __construct(
        protected ResponseService $responseService
    ) {}

    #[ApiRoute(path: '/world', methods: ['GET'], apiId: 'hello', version: '1')]
    public function worldAction(ServerRequestInterface $request): ResponseInterface {
        return $this->responseService->createSuccessResponse(['message' => 'Hello World!']);
    }
}
```

## 4. Register the Controller

Register the controller in your `Configuration/Services.php`:

```php
$services->set(\MyVendor\MyApi\Controller\HelloController::class)
    ->tag('sg_apicore.router');
```

## 5. Testing

Call the endpoint in your browser or via cURL:

```bash
curl https://your-instance.local/api/hello/v1/world
```

Result:

```json
{"message": "Hello World!"}
```
