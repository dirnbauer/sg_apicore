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

use PHPUnit\Framework\MockObject\Stub;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SGalinski\SgApiCore\Attribute\ApiCache;
use SGalinski\SgApiCore\Configuration\ExtensionConfiguration;
use SGalinski\SgApiCore\Middleware\ApiCacheMiddleware;
use SGalinski\SgApiCore\Service\ApiRegistry;
use SGalinski\SgApiCore\Service\PathAnalysisService;
use SGalinski\SgApiCore\Service\Router;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Context\Context;
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
	 * @var ApiRegistry|Stub
	 */
	protected $apiRegistry;

	/**
	 * @var Router|Stub
	 */
	protected $router;

	/**
	 * @var PathAnalysisService|Stub
	 */
	protected $pathAnalysisService;

	/**
	 * @var Context|Stub
	 */
	protected $context;

	protected function setUp(): void {
		parent::setUp();
		$this->apiRegistry = $this->createStub(ApiRegistry::class);
		$this->pathAnalysisService = $this->createStub(PathAnalysisService::class);
		$this->router = $this->createStub(Router::class);
		$this->context = $this->createStub(Context::class);
		$this->apiRegistry->method('getSecurityConfig')->willReturn(['authMode' => 'public']);
		$this->context->method('getPropertyFromAspect')->willReturn(FALSE);
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

		$this->router->method('matchEndpoint')->willReturn([
			'endpoint' => [
				'path' => '/foo',
				'methods' => ['GET'],
				'apiCache' => new ApiCache(tags: ['test']),
			],
		]);

		$cache->method('get')->willReturn([
			'status' => 200,
			'contentType' => 'application/json',
			'body' => '{"cached": true}',
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

		$this->router->method('matchEndpoint')->willReturn([
			'endpoint' => [
				'path' => '/foo',
				'methods' => ['GET'],
				'apiCache' => new ApiCache(tags: ['test']),
			],
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

		$this->router->method('matchEndpoint')->willReturn([
			'endpoint' => [
				'path' => '/foo',
				'methods' => ['POST'],
				'apiCache' => new ApiCache(tags: ['test']),
			],
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

		$this->apiRegistry = $this->createStub(ApiRegistry::class);
		$this->apiRegistry->method('getSecurityConfig')->willReturn(['authMode' => 'token']);
		$middleware = $this->createMiddlewareWithCache($cache);

		$this->router->method('matchEndpoint')->willReturn([
			'endpoint' => [
				'path' => '/test',
				'methods' => ['GET'],
				'authMode' => 'token', // PROTECTED
				'apiCache' => new ApiCache(enabled: TRUE),
			],
		]);

		$cache->expects($this->never())->method('get');

		$responseMock = $this->createStub(ResponseInterface::class);
		$responseMock->method('withHeader')->willReturnSelf();
		$handler = $this->createMock(RequestHandlerInterface::class);
		$handler->expects($this->once())->method('handle')->willReturn($responseMock);

		$middleware->process($request, $handler);
	}

	/**
	 * @test
	 */
	public function testProcessSkipsCachingForDynamicEndpointWhenApiCacheIsDisabled(): void {
		$cache = $this->createMock(FrontendInterface::class);
		$middleware = $this->createMiddlewareWithCache($cache);

		$request = new ServerRequest('https://example.com/api/test/v1/resources/news/5', 'GET');
		$request = $request->withAttribute('api.id', 'test')
			->withAttribute('api.version', 'v1')
			->withAttribute('api.remainingPath', '/resources/news/5');

		$this->router->method('matchEndpoint')->willReturn([
			'endpoint' => [
				'path' => '/resources/{resource}/{id}',
				'methods' => ['GET'],
				'apiCache' => new ApiCache(enabled: FALSE, tags: ['news']),
			],
		]);

		$cache->expects($this->never())->method('get');

		$responseMock = $this->createStub(ResponseInterface::class);
		$responseMock->method('withHeader')->willReturnSelf();
		$handler = $this->createMock(RequestHandlerInterface::class);
		$handler->expects($this->once())->method('handle')->willReturn($responseMock);

		$this->assertSame($responseMock, $middleware->process($request, $handler));
	}

	/**
	 * @test
	 */
	public function testProcessTreatsEmptyEndpointAuthModeAsEffectivePublicRouteAuthMode(): void {
		$cache = $this->createMock(FrontendInterface::class);
		$middleware = $this->createMiddlewareWithCache($cache);

		$request = new ServerRequest('https://example.com/api/test/v1/examples/5', 'GET');
		$request = $request->withAttribute('api.id', 'test')
			->withAttribute('api.version', 'v1')
			->withAttribute('api.remainingPath', '/examples/5');

		$this->router->method('matchEndpoint')->willReturn([
			'authMode' => 'public',
			'endpoint' => [
				'path' => '/examples/{id}',
				'methods' => ['GET'],
				'authMode' => [],
				'apiCache' => new ApiCache(tags: ['test']),
			],
		]);

		$cache->expects($this->once())->method('get')->willReturn(NULL);
		$cache->expects($this->once())->method('set');

		$responseMock = $this->createStub(ResponseInterface::class);
		$responseMock->method('getStatusCode')->willReturn(200);
		$responseMock->method('getHeaderLine')->with('Content-Type')->willReturn('application/json');
		$stream = new Stream('php://temp', 'wb+');
		$stream->write('{"id":5}');
		$responseMock->method('getBody')->willReturn($stream);
		$responseMock->method('withHeader')->willReturnSelf();

		$handler = $this->createMock(RequestHandlerInterface::class);
		$handler->expects($this->once())->method('handle')->willReturn($responseMock);

		$middleware->process($request, $handler);
	}

	/**
	 * @test
	 */
	public function testProcessBypassesCacheReadButStoresFreshResponseForNoCacheRequests(): void {
		$cache = $this->createMock(FrontendInterface::class);
		$middleware = $this->createMiddlewareWithCache($cache);

		$request = new ServerRequest('https://example.com/api/test/v1/examples/5', 'GET');
		$request = $request->withAttribute('api.id', 'test')
			->withAttribute('api.version', 'v1')
			->withAttribute('api.remainingPath', '/examples/5')
			->withHeader('Cache-Control', 'no-cache');

		$this->router->method('matchEndpoint')->willReturn([
			'authMode' => 'public',
			'endpoint' => [
				'path' => '/examples/{id}',
				'methods' => ['GET'],
				'apiCache' => new ApiCache(tags: ['test']),
			],
		]);

		$cache->expects($this->never())->method('get');
		$cache->expects($this->once())->method('set');

		$responseMock = $this->createStub(ResponseInterface::class);
		$responseMock->method('getStatusCode')->willReturn(200);
		$responseMock->method('getHeaderLine')->with('Content-Type')->willReturn('application/json');
		$stream = new Stream('php://temp', 'wb+');
		$stream->write('{"id":5}');
		$responseMock->method('getBody')->willReturn($stream);

		$handler = $this->createMock(RequestHandlerInterface::class);
		$handler->expects($this->once())->method('handle')->willReturn($responseMock);

		$middleware->process($request, $handler);
	}

	/**
	 * @test
	 */
	public function testProcessBypassesCacheReadAndWriteForNoStoreRequests(): void {
		$cache = $this->createMock(FrontendInterface::class);
		$middleware = $this->createMiddlewareWithCache($cache);

		$request = new ServerRequest('https://example.com/api/test/v1/examples/5', 'GET');
		$request = $request->withAttribute('api.id', 'test')
			->withAttribute('api.version', 'v1')
			->withAttribute('api.remainingPath', '/examples/5')
			->withHeader('Cache-Control', 'no-store');

		$this->router->method('matchEndpoint')->willReturn([
			'authMode' => 'public',
			'endpoint' => [
				'path' => '/examples/{id}',
				'methods' => ['GET'],
				'apiCache' => new ApiCache(tags: ['test']),
			],
		]);

		$cache->expects($this->never())->method('get');
		$cache->expects($this->never())->method('set');

		$responseMock = $this->createStub(ResponseInterface::class);
		$responseMock->method('getStatusCode')->willReturn(200);

		$handler = $this->createMock(RequestHandlerInterface::class);
		$handler->expects($this->once())->method('handle')->willReturn($responseMock);

		$middleware->process($request, $handler);
	}

	/**
	 * @test
	 */
	public function testProcessBypassesCacheReadAndWriteForBackendUsers(): void {
		$cache = $this->createMock(FrontendInterface::class);
		$this->context = $this->createStub(Context::class);
		$this->context->method('getPropertyFromAspect')->with('backend.user', 'isLoggedIn', FALSE)->willReturn(TRUE);
		$middleware = $this->createMiddlewareWithCache($cache);

		$request = new ServerRequest('https://example.com/api/test/v1/examples/5', 'GET');
		$request = $request->withAttribute('api.id', 'test')
			->withAttribute('api.version', 'v1')
			->withAttribute('api.remainingPath', '/examples/5');

		$this->router->method('matchEndpoint')->willReturn([
			'authMode' => 'public',
			'endpoint' => [
				'path' => '/examples/{id}',
				'methods' => ['GET'],
				'apiCache' => new ApiCache(tags: ['test']),
			],
		]);

		$cache->expects($this->never())->method('get');
		$cache->expects($this->never())->method('set');

		$responseMock = $this->createStub(ResponseInterface::class);
		$responseMock->method('getStatusCode')->willReturn(200);

		$handler = $this->createMock(RequestHandlerInterface::class);
		$handler->expects($this->once())->method('handle')->willReturn($responseMock);

		$middleware->process($request, $handler);
	}

	/**
	 * @test
	 */
	public function testProcessInvalidatesCacheForDynamicEndpointUsingMatchedTags(): void {
		$cache = $this->createMock(FrontendInterface::class);
		$middleware = $this->createMiddlewareWithCache($cache);

		$request = new ServerRequest('https://example.com/api/test/v1/resources/news/5', 'PATCH');
		$request = $request->withAttribute('api.id', 'test')
			->withAttribute('api.version', 'v1')
			->withAttribute('api.remainingPath', '/resources/news/5');

		$this->router->method('matchEndpoint')->willReturn([
			'endpoint' => [
				'path' => '/resources/{resource}/{id}',
				'methods' => ['PATCH'],
				'apiCache' => new ApiCache(tags: ['news']),
			],
		]);

		$cache->expects($this->once())->method('flushByTags')->with(['news']);

		$responseMock = $this->createStub(ResponseInterface::class);
		$responseMock->method('getStatusCode')->willReturn(200);

		$handler = $this->createMock(RequestHandlerInterface::class);
		$handler->expects($this->once())->method('handle')->willReturn($responseMock);

		$middleware->process($request, $handler);
	}

	protected function createMiddlewareWithCache(FrontendInterface $cache): ApiCacheMiddleware {
		$cacheManager = $this->createStub(CacheManager::class);
		$cacheManager->method('getCache')->willReturn($cache);
		$extensionConfiguration = $this->createStub(ExtensionConfiguration::class);
		$extensionConfiguration->method('isCacheEnabled')->willReturn(TRUE);

		return new ApiCacheMiddleware(
			$this->apiRegistry,
			$this->router,
			$this->pathAnalysisService,
			$cacheManager,
			$extensionConfiguration,
			$this->context
		);
	}
}
