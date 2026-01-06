<?php

/***************************************************************
 *  Copyright notice
 *
 *  (c) sgalinski Internet Services (https://www.sgalinski.de)
 *
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/

namespace SGalinski\SgApiCore\Tests\Unit\Service;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SGalinski\SgApiCore\Attribute\ApiRoute;
use SGalinski\SgApiCore\Attribute\RequireScopes;
use SGalinski\SgApiCore\Attribute\RequireUser;
use SGalinski\SgApiCore\Security\AuthContext;
use SGalinski\SgApiCore\Service\EndpointDiscoveryService;
use SGalinski\SgApiCore\Service\Router;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Test case for Router
 */
class RouterTest extends UnitTestCase {
	/**
	 * Helper to create a router with mock controllers
	 *
	 * @param array $controllers
	 * @return Router
	 */
	protected function createRouter(array $controllers = []): Router {
		$instances = [];
		foreach ($controllers as $controllerClass) {
			$instances[] = new $controllerClass();
		}
		$controllersIterator = new \ArrayIterator($instances);
		$discoveryService = new EndpointDiscoveryService($controllersIterator);
		$validator = new \SGalinski\SgApiCore\Service\RequestValidator();
		return new Router($controllersIterator, $discoveryService, $validator);
	}

	public function testDispatchMatchesRouteAndCallsController(): void {
		$request = $this->createStub(ServerRequestInterface::class);
		$request->method('getMethod')->willReturn('GET');

		$router = $this->createRouter([MockController::class]);

		$response = $router->dispatch($request, 'public', '1', '/test', 'public');

		$this->assertInstanceOf(JsonResponse::class, $response);
		$this->assertEquals(200, $response->getStatusCode());
		$this->assertEquals('{"matched":true}', (string) $response->getBody());
	}

	public function testDispatchReturns404ForUnmatchedRoute(): void {
		$request = $this->createStub(ServerRequestInterface::class);
		$request->method('getMethod')->willReturn('GET');

		$router = $this->createRouter([MockController::class]);

		$response = $router->dispatch($request, 'public', '1', '/unknown', 'public');

		$this->assertEquals(404, $response->getStatusCode());
	}

	public function testDispatchFiltersByApiId(): void {
		$request = $this->createStub(ServerRequestInterface::class);
		$request->method('getMethod')->willReturn('GET');

		$router = $this->createRouter([MockController::class]);

		// Route is restricted to 'other-api'
		$response = $router->dispatch($request, 'public', '1', '/api-restricted', 'public');

		$this->assertEquals(404, $response->getStatusCode());
	}

	public function testDispatchEnforcesScopesSuccessful(): void {
		$request = $this->createStub(ServerRequestInterface::class);
		$request->method('getMethod')->willReturn('GET');

		$authContext = new AuthContext('public', 'tenant-1', 1, ['read']);
		$request->method('getAttribute')->with('api.auth')->willReturn($authContext);

		$router = $this->createRouter([MockController::class]);

		$response = $router->dispatch($request, 'public', '1', '/scoped', 'public');

		$this->assertEquals(200, $response->getStatusCode());
	}

	public function testDispatchEnforcesScopesFailsWith403(): void {
		$request = $this->createStub(ServerRequestInterface::class);
		$request->method('getMethod')->willReturn('GET');

		$authContext = new AuthContext('public', 'tenant-1', 1, ['wrong-scope']);
		$request->method('getAttribute')->with('api.auth')->willReturn($authContext);

		$router = $this->createRouter([MockController::class]);

		$response = $router->dispatch($request, 'public', '1', '/scoped', 'public');

		$this->assertEquals(403, $response->getStatusCode());
	}

	public function testDispatchEnforcesScopesFailsWith401WhenNoAuth(): void {
		$request = $this->createStub(ServerRequestInterface::class);
		$request->method('getMethod')->willReturn('GET');
		$request->method('getAttribute')->with('api.auth')->willReturn(NULL);

		$router = $this->createRouter([MockController::class]);

		$response = $router->dispatch($request, 'public', '1', '/scoped', 'token');

		$this->assertEquals(401, $response->getStatusCode());
	}

	public function testDispatchFiltersByAuthMode(): void {
		$request = $this->createStub(ServerRequestInterface::class);
		$request->method('getMethod')->willReturn('GET');

		$router = $this->createRouter([MockController::class]);

		// Route is restricted to authMode 'user'
		$response = $router->dispatch($request, 'public', '1', '/user-only', 'token');
		$this->assertEquals(404, $response->getStatusCode());

		// In 'user' API, it returns 401 if not authenticated
		$response = $router->dispatch($request, 'public', '1', '/user-only', 'user');
		$this->assertEquals(401, $response->getStatusCode());

		// With auth, it works
		$authContext = new AuthContext('public', 'tenant-1', 1);
		$request->method('getAttribute')->with('api.auth')->willReturn($authContext);
		$response = $router->dispatch($request, 'public', '1', '/user-only', 'user');
		$this->assertEquals(200, $response->getStatusCode());
	}

	public function testDispatchAllowsPublicEndpointInUserApi(): void {
		$request = $this->createStub(ServerRequestInterface::class);
		$request->method('getMethod')->willReturn('GET');

		$router = $this->createRouter([MockController::class]);

		// /login is marked as public, so it should work in 'user' API without auth
		$response = $router->dispatch($request, 'public', '1', '/login', 'user');
		$this->assertEquals(200, $response->getStatusCode());
	}

	public function testDispatchFiltersHybridAuthModeCorrectly(): void {
		$request = $this->createStub(ServerRequestInterface::class);
		$request->method('getMethod')->willReturn('GET');

		$router = $this->createRouter([MockHybridRouterController::class]);

		// 'public' api should NOT match /hybrid
		$response = $router->dispatch($request, 'public', '1', '/hybrid', 'public');
		$this->assertEquals(404, $response->getStatusCode());

		// 'user' api SHOULD match /hybrid and it should be accessible without auth
		$response = $router->dispatch($request, 'user', '1', '/hybrid', 'user');
		$this->assertEquals(200, $response->getStatusCode());
	}

	public function testDispatchEnforcesRequireUser(): void {
		$request = $this->createStub(ServerRequestInterface::class);
		$request->method('getMethod')->willReturn('GET');

		$router = $this->createRouter([MockController::class]);

		// 1. No auth -> 401
		$response = $router->dispatch($request, 'public', '1', '/user-required', 'user');
		$this->assertEquals(401, $response->getStatusCode());

		// 2. Auth but no userId (M2M token) -> 403
		$authContextNoUser = new AuthContext('public', 'tenant-1', 1);
		$request->method('getAttribute')->with('api.auth')->willReturn($authContextNoUser);
		$response = $router->dispatch($request, 'public', '1', '/user-required', 'user');
		$this->assertEquals(403, $response->getStatusCode());

		// 3. Auth with userId -> 200
		$request = $this->createStub(ServerRequestInterface::class);
		$request->method('getMethod')->willReturn('GET');
		$authContextWithUser = new AuthContext('public', 'tenant-1', 1, [], 123);
		$request->method('getAttribute')->with('api.auth')->willReturn($authContextWithUser);
		$response = $router->dispatch($request, 'public', '1', '/user-required', 'user');
		$this->assertEquals(200, $response->getStatusCode());
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

	#[ApiRoute(path: '/user-only', methods: ['GET'], authMode: 'user')]
	public function userOnlyAction(ServerRequestInterface $request): ResponseInterface {
		return new JsonResponse(['matched' => TRUE]);
	}

	#[ApiRoute(path: '/login', methods: ['GET'], authMode: 'public')]
	public function loginAction(ServerRequestInterface $request): ResponseInterface {
		return new JsonResponse(['matched' => TRUE]);
	}

	#[ApiRoute(path: '/scoped', methods: ['GET'])]
	#[RequireScopes(['read'])]
	public function scopedAction(ServerRequestInterface $request): ResponseInterface {
		return new JsonResponse(['matched' => TRUE]);
	}

	#[ApiRoute(path: '/user-required', methods: ['GET'])]
	#[RequireUser]
	public function userRequiredAction(ServerRequestInterface $request): ResponseInterface {
		return new JsonResponse(['matched' => TRUE]);
	}
}

/**
 * Mock controller for hybrid auth
 */
class MockHybridRouterController {
	#[ApiRoute(path: '/hybrid', methods: ['GET'], authMode: ['user', 'public'])]
	public function hybridAction(ServerRequestInterface $request): ResponseInterface {
		return new JsonResponse(['matched' => TRUE]);
	}
}
