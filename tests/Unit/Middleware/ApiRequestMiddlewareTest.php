<?php

namespace SGalinski\SgApiCore\Tests\Unit\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SGalinski\SgApiCore\Configuration\ExtensionConfiguration;
use SGalinski\SgApiCore\Middleware\ApiRequestMiddleware;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Test case for ApiRequestMiddleware
 */
class ApiRequestMiddlewareTest extends UnitTestCase {
	public function testProcessReturnsJsonResponseForHealthEndpoint(): void {
		$request = $this->createStub(ServerRequestInterface::class);
		$uri = $this->createStub(UriInterface::class);
		$uri->method('getPath')->willReturn('/api/health');
		$request->method('getUri')->willReturn($uri);

		$handler = $this->createMock(RequestHandlerInterface::class);
		$handler->expects($this->never())->method('handle');

		$extensionConfiguration = $this->createStub(ExtensionConfiguration::class);
		$extensionConfiguration->method('getApiPathPrefix')->willReturn('/api/');

		$middleware = new ApiRequestMiddleware($extensionConfiguration);
		$response = $middleware->process($request, $handler);

		$this->assertInstanceOf(JsonResponse::class, $response);
		$this->assertEquals(200, $response->getStatusCode());
		$this->assertEquals('{"status":"ok"}', (string) $response->getBody());
	}

	public function testProcessDelegatesToHandlerForNonApiRequests(): void {
		$request = $this->createStub(ServerRequestInterface::class);
		$uri = $this->createStub(UriInterface::class);
		$uri->method('getPath')->willReturn('/some-other-path');
		$request->method('getUri')->willReturn($uri);

		$responseMock = $this->createStub(ResponseInterface::class);
		$handler = $this->createMock(RequestHandlerInterface::class);
		$handler->expects($this->once())->method('handle')->with($request)->willReturn($responseMock);

		$extensionConfiguration = $this->createStub(ExtensionConfiguration::class);
		$extensionConfiguration->method('getApiPathPrefix')->willReturn('/api/');

		$middleware = new ApiRequestMiddleware($extensionConfiguration);
		$response = $middleware->process($request, $handler);

		$this->assertSame($responseMock, $response);
	}
}
