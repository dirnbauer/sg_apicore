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
use SGalinski\SgApiCore\Attribute\ApiCache;
use SGalinski\SgApiCore\Configuration\ExtensionConfiguration;
use SGalinski\SgApiCore\Middleware\ApiCacheMiddleware;
use SGalinski\SgApiCore\Service\EndpointDiscoveryService;
use SGalinski\SgApiCore\Service\PathAnalysisService;
use SGalinski\SgApiCore\Service\ResponseService;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Test case for ApiCacheMiddleware
 */
class ApiCacheMiddlewareTest extends UnitTestCase {
	/**
	 * @var bool
	 */
	protected bool $resetSingletonInstances = TRUE;

	/**
	 * @var ApiCacheMiddleware
	 */
	protected ApiCacheMiddleware $middleware;

	/**
	 * @var EndpointDiscoveryService|\PHPUnit\Framework\MockObject\Stub
	 */
	protected $discoveryService;

	/**
	 * @var PathAnalysisService|\PHPUnit\Framework\MockObject\Stub
	 */
	protected $pathAnalysisService;

	protected function setUp(): void {
		parent::setUp();
		$this->discoveryService = $this->createStub(EndpointDiscoveryService::class);
		$this->pathAnalysisService = $this->createStub(PathAnalysisService::class);
	}

	protected function createMiddlewareWithCache(FrontendInterface $cache): ApiCacheMiddleware {
		$cacheManager = $this->createStub(CacheManager::class);
		$cacheManager->method('getCache')->willReturn($cache);
		$extensionConfiguration = $this->createStub(ExtensionConfiguration::class);
		$extensionConfiguration->method('isCacheEnabled')->willReturn(TRUE);

		return new ApiCacheMiddleware(
			$this->discoveryService,
			$this->pathAnalysisService,
			$cacheManager,
			$extensionConfiguration
		);
	}

	/**
	 * @test
	 */
	public function testProcessReturnsCachedResponseIfAvailable(): void {
		$cache = $this->createStub(FrontendInterface::class);
		$middleware = $this->createMiddlewareWithCache($cache);

		$request = new ServerRequest('https://example.com/api/test/v1/foo', 'GET');
		$request = $request->withAttribute('api.id', 'test')
			->withAttribute('api.version', 'v1')
			->withAttribute('api.remainingPath', '/foo');

		$this->discoveryService->method('getEndpointsForApi')->willReturn([
			[
				'path' => '/foo',
				'methods' => ['GET'],
				'apiCache' => new ApiCache(tags: ['test'])
			]
		]);

		$cache->method('get')->willReturn([
			'status' => 200,
			'contentType' => 'application/json',
			'body' => '{"cached": true}'
		]);

		$handler = $this->createStub(RequestHandlerInterface::class);

		$response = $middleware->process($request, $handler);

		$this->assertEquals(200, $response->getStatusCode());
		$this->assertEquals('application/json', $response->getHeaderLine('Content-Type'));
		$this->assertEquals('HIT', $response->getHeaderLine('X-TYPO3-API-Cache'));
		$this->assertEquals('{"cached": true}', (string) $response->getBody());
	}

	/**
	 * @test
	 */
	public function testProcessStoresResponseInCacheOnMiss(): void {
		$cache = $this->createMock(FrontendInterface::class);
		$middleware = $this->createMiddlewareWithCache($cache);

		$request = new ServerRequest('https://example.com/api/test/v1/foo', 'GET');
		$request = $request->withAttribute('api.id', 'test')
			->withAttribute('api.version', 'v1')
			->withAttribute('api.remainingPath', '/foo');

		$this->discoveryService->method('getEndpointsForApi')->willReturn([
			[
				'path' => '/foo',
				'methods' => ['GET'],
				'apiCache' => new ApiCache(tags: ['test'])
			]
		]);

		$cache->method('get')->willReturn(NULL);
		$cache->expects($this->once())->method('set');

		$responseMock = $this->createStub(ResponseInterface::class);
		$responseMock->method('getStatusCode')->willReturn(200);
		$responseMock->method('getHeaderLine')->with('Content-Type')->willReturn('application/json');
		$stream = new Stream('php://temp', 'wb+');
		$stream->write('{"success": true}');
		$responseMock->method('getBody')->willReturn($stream);
		$responseMock->method('withHeader')->willReturnSelf();

		$handler = $this->createStub(RequestHandlerInterface::class);
		$handler->method('handle')->willReturn($responseMock);

		$middleware->process($request, $handler);
	}

	/**
	 * @test
	 */
	public function testProcessInvalidatesCacheOnWriteRequest(): void {
		$cache = $this->createMock(FrontendInterface::class);
		$middleware = $this->createMiddlewareWithCache($cache);

		$request = new ServerRequest('https://example.com/api/test/v1/foo', 'POST');
		$request = $request->withAttribute('api.id', 'test')
			->withAttribute('api.version', 'v1')
			->withAttribute('api.remainingPath', '/foo');

		$this->discoveryService->method('getEndpointsForApi')->willReturn([
			[
				'path' => '/foo',
				'methods' => ['POST'],
				'apiCache' => new ApiCache(tags: ['test'])
			]
		]);

		$cache->expects($this->once())->method('flushByTags')->with(['test']);

		$responseMock = $this->createStub(ResponseInterface::class);
		$responseMock->method('getStatusCode')->willReturn(201);

		$handler = $this->createMock(RequestHandlerInterface::class);
		$handler->expects($this->once())->method('handle')->willReturn($responseMock);

		$middleware->process($request, $handler);
	}

	public function testHandleGetRequestReturnsHitOnlyIfAuthenticatedForProtectedEndpoint(): void {
		$cache = $this->createMock(FrontendInterface::class);
		$middleware = $this->createMiddlewareWithCache($cache);

		$request = $this->createStub(ServerRequestInterface::class);
		$request->method('getMethod')->willReturn('GET');
		$request->method('getAttribute')->willReturnMap([
			['api.id', 'test'],
			['api.version', '1'],
			['api.remainingPath', '/test'],
			['api.auth', NULL], // NOT AUTHENTICATED
		]);
		$uri = $this->createStub(UriInterface::class);
		$uri->method('getPath')->willReturn('/api/test/v1/test');
		$request->method('getUri')->willReturn($uri);

		$this->discoveryService->method('getEndpointsForApi')->willReturn([
			[
				'path' => '/test',
				'methods' => ['GET'],
				'authMode' => 'token', // PROTECTED
				'apiCache' => new ApiCache(enabled: TRUE)
			]
		]);

		$cache->expects($this->never())->method('get');

		$handler = $this->createMock(RequestHandlerInterface::class);
		$handler->expects($this->once())->method('handle')->willReturn($this->createStub(ResponseInterface::class));

		$middleware->process($request, $handler);
	}
}
