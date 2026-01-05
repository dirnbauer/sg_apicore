<?php

namespace SGalinski\SgApiCore\Tests\Unit\Service;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SGalinski\SgApiCore\Attribute\ApiRoute;
use SGalinski\SgApiCore\Service\Router;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Test case for Router
 */
class RouterTest extends UnitTestCase {
	public function testDispatchMatchesRouteAndCallsController(): void {
		$request = $this->createStub(ServerRequestInterface::class);
		$request->method('getMethod')->willReturn('GET');

		$router = new Router();
		$router->setControllers([MockController::class]);

		$response = $router->dispatch($request, 'public', '1', '/test');

		$this->assertInstanceOf(JsonResponse::class, $response);
		$this->assertEquals(200, $response->getStatusCode());
		$this->assertEquals('{"matched":true}', (string) $response->getBody());
	}

	public function testDispatchReturns404ForUnmatchedRoute(): void {
		$request = $this->createStub(ServerRequestInterface::class);
		$request->method('getMethod')->willReturn('GET');

		$router = new Router();
		$router->setControllers([MockController::class]);

		$response = $router->dispatch($request, 'public', '1', '/unknown');

		$this->assertEquals(404, $response->getStatusCode());
	}

	public function testDispatchFiltersByApiId(): void {
		$request = $this->createStub(ServerRequestInterface::class);
		$request->method('getMethod')->willReturn('GET');

		$router = new Router();
		$router->setControllers([MockController::class]);

		// Route is restricted to 'other-api'
		$response = $router->dispatch($request, 'public', '1', '/api-restricted');

		$this->assertEquals(404, $response->getStatusCode());
	}
}

/**
 * Mock controller for testing
 */
class MockController {
	#[ApiRoute(path: '/test', methods: ['GET'])]
	public function testAction(ServerRequestInterface $request): ResponseInterface {
		return new JsonResponse(['matched' => TRUE]);
	}

	#[ApiRoute(path: '/api-restricted', methods: ['GET'], apiId: 'other-api')]
	public function restrictedAction(ServerRequestInterface $request): ResponseInterface {
		return new JsonResponse(['matched' => TRUE]);
	}
}
