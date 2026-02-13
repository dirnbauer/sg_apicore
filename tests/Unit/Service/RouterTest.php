<?php

/***************************************************************
 *  Copyright notice
 *  (c) sgalinski Internet Services (https://www.sgalinski.de)
 *
 *  All rights reserved
 *
 *  This file is part of the TYPO3 CMS project.
 *  It is free software; you can redistribute it and/or modify it under
 *  the terms of the "GNU General Public License", either version 3
 *  of the License or any later version.
 ***************************************************************/

namespace SGalinski\SgApiCore\Tests\Unit\Service;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SGalinski\SgApiCore\Attribute\ApiRoute;
use SGalinski\SgApiCore\Attribute\RequireScopes;
use SGalinski\SgApiCore\Attribute\RequireUser;
use SGalinski\SgApiCore\Context\TenantContext;
use SGalinski\SgApiCore\Security\AuthContext;
use SGalinski\SgApiCore\Service\EndpointDiscoveryService;
use SGalinski\SgApiCore\Service\ResourceRegistry;
use SGalinski\SgApiCore\Service\ResponseService;
use SGalinski\SgApiCore\Service\Router;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
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
		$resourceRegistry = $this->createStub(ResourceRegistry::class);
		$resourceRegistry->method('getResources')->willReturn([]);

		$cache = $this->createStub(FrontendInterface::class);
		$cache->method('get')->willReturn(NULL);
		$cacheManager = $this->createStub(CacheManager::class);
		$cacheManager->method('getCache')->with('sg_apicore_discovery')->willReturn($cache);
		$languageServiceFactory = $this->createStub(LanguageServiceFactory::class);

		$discoveryService = new EndpointDiscoveryService(
			$controllersIterator,
			$resourceRegistry,
			$cacheManager,
			$languageServiceFactory
		);
		$validator = new \SGalinski\SgApiCore\Service\RequestValidator();
		$responseService = $this->createStub(ResponseService::class);
		$responseService->method('createErrorResponse')->willReturnCallback(function ($title, $detail, $status) {
			return new JsonResponse(['title' => $title, 'detail' => $detail], $status);
		});
		return new Router($controllersIterator, $discoveryService, $validator, $responseService);
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
		$request = $this->createMock(ServerRequestInterface::class);
		$request->method('getMethod')->willReturn('GET');
		$tenantContext = new TenantContext('tenant-1');
		$authContext = new AuthContext('public', 'tenant-1', 1, ['read', 'write']);

		$request->method('getAttribute')->willReturnCallback(function ($name) use ($tenantContext, $authContext) {
			if ($name === 'api.tenant') {
				return $tenantContext;
			}
			if ($name === 'api.auth') {
				return $authContext;
			}
			return NULL;
		});
		$request->method('withAttribute')->willReturn($request);

		$router = $this->createRouter([MockController::class]);

		$response = $router->dispatch($request, 'public', '1', '/scoped', 'public');

		$this->assertEquals(200, $response->getStatusCode());
	}

	public function testDispatchEnforcesScopesFailsWith403(): void {
		$request = new ServerRequest('https://example.com/api/public/v1/scoped', 'GET');
		$authContext = new AuthContext('public', 'tenant-1', 1, ['wrong-scope']);
		$request = $request->withAttribute('api.auth', $authContext);

		$router = $this->createRouter([MockController::class]);

		$response = $router->dispatch($request, 'public', '1', '/scoped', 'public');

		$this->assertEquals(403, $response->getStatusCode());
	}

	public function testDispatchEnforcesScopesFailsWith401WhenNoAuth(): void {
		$request = new ServerRequest('https://example.com/api/public/v1/scoped', 'GET');
		$router = $this->createRouter([MockController::class]);

		$response = $router->dispatch($request, 'public', '1', '/scoped', 'token');

		$this->assertEquals(401, $response->getStatusCode());
	}

	public function testDispatchFiltersByAuthMode(): void {
		$request = $this->createMock(ServerRequestInterface::class);
		$request->method('getMethod')->willReturn('GET');
		$tenantContext = new TenantContext('tenant-1');
		$request->method('getAttribute')->willReturnCallback(function ($name) use ($tenantContext) {
			if ($name === 'api.tenant') {
				return $tenantContext;
			}
			return NULL;
		});
		$request->method('withAttribute')->willReturn($request);

		$router = $this->createRouter([MockController::class]);

		// Route is restricted to authMode 'user'
		$response = $router->dispatch($request, 'public', '1', '/user-only', 'token');
		$this->assertEquals(404, $response->getStatusCode());

		// In 'user' API, it returns 401 if not authenticated
		$response = $router->dispatch($request, 'public', '1', '/user-only', 'user');
		$this->assertEquals(401, $response->getStatusCode());

		// With auth, it works
		$authContext = new AuthContext('public', 'tenant-1', 1);
		$request = $this->createMock(ServerRequestInterface::class);
		$request->method('getMethod')->willReturn('GET');
		$request->method('getAttribute')->willReturnCallback(function ($name) use ($tenantContext, $authContext) {
			if ($name === 'api.tenant') {
				return $tenantContext;
			}
			if ($name === 'api.auth') {
				return $authContext;
			}
			return NULL;
		});
		$request->method('withAttribute')->willReturn($request);
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

	public function testDispatchFiltersMixedAuthModeCorrectly(): void {
		$request = $this->createStub(ServerRequestInterface::class);
		$request->method('getMethod')->willReturn('GET');

		$router = $this->createRouter([MockMixedRouterController::class]);

		// 1. 'public' api should NOT match /mixed
		$response = $router->dispatch($request, 'public', '1', '/mixed', 'public');
		$this->assertEquals(404, $response->getStatusCode());

		// 2. 'token' api SHOULD match /mixed
		$response = $router->dispatch($request, 'token-api', '1', '/mixed', 'token');
		$this->assertEquals(401, $response->getStatusCode()); // Matches but no auth

		// 3. 'user' api SHOULD match /mixed
		$response = $router->dispatch($request, 'user-api', '1', '/mixed', 'user');
		$this->assertEquals(401, $response->getStatusCode()); // Matches but no auth
	}

	public function testDispatchEnforcesRequireUser(): void {
		$request = new ServerRequest('https://example.com/api/public/v1/user-required', 'GET');
		$router = $this->createRouter([MockController::class]);

		// 1. No auth -> 401
		$response = $router->dispatch($request, 'public', '1', '/user-required', 'user');
		$this->assertEquals(401, $response->getStatusCode());

		// 2. Auth but no userId (M2M token) -> 403
		$authContextNoUser = new AuthContext('public', 'tenant-1', 1);
		$requestWithAuth = $request->withAttribute('api.auth', $authContextNoUser);
		$response = $router->dispatch($requestWithAuth, 'public', '1', '/user-required', 'user');
		$this->assertEquals(403, $response->getStatusCode());

		// 3. Auth with userId -> 200
		$authContextWithUser = new AuthContext('public', 'tenant-1', 1, [], 123);
		$requestWithUserAuth = $request->withAttribute('api.auth', $authContextWithUser);
		$response = $router->dispatch($requestWithUserAuth, 'public', '1', '/user-required', 'user');
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

/**
 * Mock controller for mixed token/user auth
 */
class MockMixedRouterController {
	#[ApiRoute(path: '/mixed', methods: ['GET'], authMode: ['token', 'user'])]
	public function mixedAction(ServerRequestInterface $request): ResponseInterface {
		return new JsonResponse(['matched' => TRUE]);
	}
}
