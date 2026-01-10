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
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Test case for ApiRequestMiddleware
 */
class ApiRequestMiddlewareTest extends UnitTestCase {
	protected $extensionConfiguration;
	protected $apiRegistry;
	protected $router;
	protected $pathAnalysisService;
	protected $responseService;
	protected $middleware;

	protected function setUp(): void {
		parent::setUp();
		$this->extensionConfiguration = $this->createStub(ExtensionConfiguration::class);
		$this->extensionConfiguration->method('getApiPathPrefix')->willReturn('/api/');

		$this->apiRegistry = $this->createStub(ApiRegistry::class);
		$this->router = $this->createStub(Router::class);
		$this->pathAnalysisService = $this->createStub(PathAnalysisService::class);
		$this->responseService = $this->createStub(ResponseService::class);

		$this->middleware = new ApiRequestMiddleware(
			$this->extensionConfiguration,
			$this->apiRegistry,
			$this->router,
			$this->pathAnalysisService,
			$this->responseService
		);
	}

	/**
	 * @test
	 */
	public function testProcessHandlesLanguagePrefix(): void {
		$request = $this->createStub(ServerRequestInterface::class);
		$uri = $this->createStub(UriInterface::class);
		$uri->method('getPath')->willReturn('/en/api/test/v1/foo');
		$request->method('getUri')->willReturn($uri);

		$language = $this->createStub(\TYPO3\CMS\Core\Site\Entity\SiteLanguage::class);
		$base = $this->createStub(UriInterface::class);
		$base->method('getPath')->willReturn('/en/');
		$language->method('getBase')->willReturn($base);

		$request->method('getAttribute')->willReturnMap([
			['language', $language],
			['api.id', 'test'],
			['api.version', '1'],
			['api.remainingPath', '/foo'],
		]);

		$this->apiRegistry->method('hasApi')->with('test')->willReturn(TRUE);
		$this->apiRegistry->method('getApi')->with('test')->willReturn(['versions' => ['1']]);
		$this->apiRegistry->method('getSecurityConfig')->willReturn(['authMode' => 'token']);

		$this->router = $this->createMock(Router::class);
		$this->router->expects($this->once())
			->method('dispatch')
			->with($request, 'test', '1', '/foo', 'token')
			->willReturn($this->createStub(ResponseInterface::class));

		$middleware = new ApiRequestMiddleware(
			$this->extensionConfiguration,
			$this->apiRegistry,
			$this->router,
			$this->pathAnalysisService,
			$this->responseService
		);

		$middleware->process($request, $this->createStub(RequestHandlerInterface::class));
	}

	/**
	 * @test
	 */
	public function testProcessReturnsJsonResponseForHealthEndpoint(): void {
		$request = $this->createStub(ServerRequestInterface::class);
		$uri = $this->createStub(UriInterface::class);
		$uri->method('getPath')->willReturn('/api/health');
		$request->method('getUri')->willReturn($uri);

		$handler = $this->createStub(RequestHandlerInterface::class);

		$response = $this->middleware->process($request, $handler);
		$this->assertInstanceOf(JsonResponse::class, $response);
		$this->assertEquals(200, $response->getStatusCode());
	}

	/**
	 * @test
	 */
	public function testProcessDelegatesToRouterForValidApiRequest(): void {
		$request = $this->createStub(ServerRequestInterface::class);
		$uri = $this->createStub(UriInterface::class);
		$uri->method('getPath')->willReturn('/api/test/v1/foo');
		$request->method('getUri')->willReturn($uri);
		$request->method('getAttribute')->willReturnMap([
			['api.id', 'test'],
			['api.version', '1'],
			['api.remainingPath', '/foo'],
		]);

		$this->apiRegistry->method('hasApi')->with('test')->willReturn(TRUE);
		$this->apiRegistry->method('getApi')->with('test')->willReturn(['versions' => ['1']]);
		$this->apiRegistry->method('getSecurityConfig')->willReturn(['authMode' => 'public']);

		$responseMock = $this->createStub(ResponseInterface::class);
		$router = $this->createMock(Router::class);
		$router->expects($this->once())
			->method('dispatch')
			->willReturn($responseMock);

		$middleware = new ApiRequestMiddleware(
			$this->extensionConfiguration,
			$this->apiRegistry,
			$router,
			$this->pathAnalysisService,
			$this->responseService
		);

		$handler = $this->createStub(RequestHandlerInterface::class);
		$result = $middleware->process($request, $handler);
		$this->assertSame($responseMock, $result);
	}

	/**
	 * @test
	 */
	public function testProcessDelegatesToHandlerForNonApiRequests(): void {
		$request = $this->createStub(ServerRequestInterface::class);
		$uri = $this->createStub(UriInterface::class);
		$uri->method('getPath')->willReturn('/other/path');
		$request->method('getUri')->willReturn($uri);

		$responseMock = $this->createStub(ResponseInterface::class);
		$handler = $this->createMock(RequestHandlerInterface::class);
		$handler->expects($this->once())->method('handle')->willReturn($responseMock);

		$result = $this->middleware->process($request, $handler);
		$this->assertSame($responseMock, $result);
	}
}
