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

namespace SGalinski\SgApiCore\Tests\Unit\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SGalinski\SgApiCore\Configuration\ExtensionConfiguration;
use SGalinski\SgApiCore\Middleware\ApiRequestMiddleware;
use SGalinski\SgApiCore\Service\ApiRegistry;
use SGalinski\SgApiCore\Service\PathAnalysisService;
use SGalinski\SgApiCore\Service\ResponseService;
use SGalinski\SgApiCore\Service\Router;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Extbase\Persistence\PersistenceManagerInterface;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Test case for ApiRequestMiddleware
 */
#[\PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations]
class ApiRequestMiddlewareTest extends UnitTestCase {
	protected $extensionConfiguration;
	protected $apiRegistry;
	protected $router;
	protected $pathAnalysisService;
	protected $responseService;
	protected $persistenceManager;
	protected $middleware;

	protected function setUp(): void {
		parent::setUp();
		$this->extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
		$this->extensionConfiguration->method('getApiPathPrefix')->willReturn('/api/');

		$this->apiRegistry = $this->createMock(ApiRegistry::class);
		$this->router = $this->createMock(Router::class);
		$this->pathAnalysisService = $this->createMock(PathAnalysisService::class);
		$this->responseService = $this->createMock(ResponseService::class);
		$this->persistenceManager = $this->createMock(PersistenceManagerInterface::class);

		$this->middleware = new ApiRequestMiddleware(
			$this->extensionConfiguration,
			$this->apiRegistry,
			$this->router,
			$this->pathAnalysisService,
			$this->responseService,
			$this->persistenceManager
		);
	}

	/**
	 * @test
	 */
	public function testProcessHandlesLanguagePrefix(): void {
		$request = $this->createMock(ServerRequestInterface::class);
		$uri = $this->createMock(UriInterface::class);
		$uri->method('getPath')->willReturn('/en/api/test/v1/foo');
		$request->method('getUri')->willReturn($uri);
		$request->method('hasHeader')->willReturn(FALSE);

		$language = $this->createMock(\TYPO3\CMS\Core\Site\Entity\SiteLanguage::class);
		$base = $this->createMock(UriInterface::class);
		$base->method('getPath')->willReturn('/en/');
		$language->method('getBase')->willReturn($base);

		$request->method('getAttribute')->willReturnCallback(static function ($name) use ($language) {
			if ($name === 'language') {
				return $language;
			}
			if ($name === 'api.id') {
				return 'test';
			}
			if ($name === 'api.version') {
				return '1';
			}
			if ($name === 'api.remainingPath') {
				return '/foo';
			}
			return NULL;
		});

		$this->apiRegistry->method('hasApi')->with('test')->willReturn(TRUE);
		$this->apiRegistry->method('getApi')->with('test')->willReturn(['versions' => ['1']]);
		$this->apiRegistry->method('getSecurityConfig')->willReturn(['authMode' => 'token']);

		$this->router = $this->createMock(Router::class);
		$this->router->expects($this->once())
			->method('dispatch')
			->with($request, 'test', '1', '/foo', 'token')
			->willReturn($this->createMock(ResponseInterface::class));

		$middleware = new ApiRequestMiddleware(
			$this->extensionConfiguration,
			$this->apiRegistry,
			$this->router,
			$this->pathAnalysisService,
			$this->responseService,
			$this->persistenceManager
		);

		$middleware->process($request, $this->createMock(RequestHandlerInterface::class));
	}

	/**
	 * @test
	 */
	public function testProcessCastsAuthModeToString(): void {
		$request = $this->createMock(ServerRequestInterface::class);
		$uri = $this->createMock(UriInterface::class);
		$uri->method('getPath')->willReturn('/api/test/v1/foo');
		$request->method('getUri')->willReturn($uri);
		$request->method('getAttribute')->willReturnCallback(static function ($name) {
			if ($name === 'api.id') {
				return 'test';
			}
			if ($name === 'api.version') {
				return '1';
			}
			if ($name === 'api.remainingPath') {
				return '/foo';
			}
			return NULL;
		});
		$request->method('hasHeader')->willReturn(FALSE);

		$this->apiRegistry->method('hasApi')->with('test')->willReturn(TRUE);
		$this->apiRegistry->method('getApi')->with('test')->willReturn(['versions' => ['1']]);

		// Simulate invalid config: authMode is an array (should be string on API level)
		$this->apiRegistry->method('getSecurityConfig')->willReturn(['authMode' => ['user']]);

		$this->router = $this->createMock(Router::class);
		$this->router->expects($this->once())
			->method('dispatch')
			->with($this->anything(), $this->anything(), $this->anything(), $this->anything(), 'user')
			->willReturn($this->createMock(ResponseInterface::class));

		$middleware = new ApiRequestMiddleware(
			$this->extensionConfiguration,
			$this->apiRegistry,
			$this->router,
			$this->pathAnalysisService,
			$this->responseService,
			$this->persistenceManager
		);

		$middleware->process($request, $this->createMock(RequestHandlerInterface::class));
	}

	/**
	 * @test
	 */
	public function testProcessReturnsJsonResponseForHealthEndpoint(): void {
		$request = $this->createMock(ServerRequestInterface::class);
		$uri = $this->createMock(UriInterface::class);
		$uri->method('getPath')->willReturn('/api/health');
		$request->method('getUri')->willReturn($uri);
		$request->method('hasHeader')->willReturn(FALSE);

		$handler = $this->createMock(RequestHandlerInterface::class);

		$response = $this->middleware->process($request, $handler);
		$this->assertInstanceOf(JsonResponse::class, $response);
		$this->assertEquals(200, $response->getStatusCode());
	}

	/**
	 * @test
	 */
	public function testProcessDelegatesToRouterForValidApiRequest(): void {
		$request = $this->createMock(ServerRequestInterface::class);
		$uri = $this->createMock(UriInterface::class);
		$uri->method('getPath')->willReturn('/api/test/v1/foo');
		$request->method('getUri')->willReturn($uri);
		$request->method('getAttribute')->willReturnCallback(static function ($name) {
			if ($name === 'api.id') {
				return 'test';
			}
			if ($name === 'api.version') {
				return '1';
			}
			if ($name === 'api.remainingPath') {
				return '/foo';
			}
			return NULL;
		});
		$request->method('hasHeader')->willReturn(FALSE);

		$this->apiRegistry->method('hasApi')->with('test')->willReturn(TRUE);
		$this->apiRegistry->method('getApi')->with('test')->willReturn(['versions' => ['1']]);
		$this->apiRegistry->method('getSecurityConfig')->willReturn(['authMode' => 'public']);

		$responseMock = $this->createMock(ResponseInterface::class);
		$router = $this->createMock(Router::class);
		$router->expects($this->once())
			->method('dispatch')
			->willReturn($responseMock);

		$middleware = new ApiRequestMiddleware(
			$this->extensionConfiguration,
			$this->apiRegistry,
			$router,
			$this->pathAnalysisService,
			$this->responseService,
			$this->persistenceManager
		);

		$handler = $this->createMock(RequestHandlerInterface::class);
		$result = $middleware->process($request, $handler);
		$this->assertSame($responseMock, $result);
	}

	/**
	 * @test
	 */
	public function testProcessDelegatesToHandlerForNonApiRequests(): void {
		$request = $this->createMock(ServerRequestInterface::class);
		$uri = $this->createMock(UriInterface::class);
		$uri->method('getPath')->willReturn('/other/path');
		$request->method('getUri')->willReturn($uri);
		$request->method('hasHeader')->willReturn(FALSE);

		$responseMock = $this->createMock(ResponseInterface::class);
		$handler = $this->createMock(RequestHandlerInterface::class);
		$handler->expects($this->once())->method('handle')->willReturn($responseMock);

		$result = $this->middleware->process($request, $handler);
		$this->assertSame($responseMock, $result);
	}
}
