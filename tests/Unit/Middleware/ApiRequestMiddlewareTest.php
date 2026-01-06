<?php

namespace SGalinski\SgApiCore\Tests\Unit\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SGalinski\SgApiCore\Configuration\ExtensionConfiguration;
use SGalinski\SgApiCore\Context\TenantContext;
use SGalinski\SgApiCore\Middleware\ApiRequestMiddleware;
use SGalinski\SgApiCore\Security\LoginProviderInterface;
use SGalinski\SgApiCore\Service\ApiRegistry;
use SGalinski\SgApiCore\Service\LogService;
use SGalinski\SgApiCore\Service\Router;
use SGalinski\SgApiCore\Service\Tenant\TenantContextResult;
use SGalinski\SgApiCore\Service\Tenant\TenantResolverInterface;
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
		$request->method('getAttribute')->willReturn(NULL);
		$request->method('withAttribute')->willReturn($request);

		$handler = $this->createStub(RequestHandlerInterface::class);

		$extensionConfiguration = $this->createStub(ExtensionConfiguration::class);
		$extensionConfiguration->method('getApiPathPrefix')->willReturn('/api/');

		$apiRegistry = $this->createStub(ApiRegistry::class);
		$router = $this->createStub(Router::class);
		$tenantResolver = $this->createStub(TenantResolverInterface::class);
		$tenantResolver->method('resolve')->willReturn(
			TenantContextResult::success(new TenantContext('test-tenant'))
		);

		$middleware = new ApiRequestMiddleware(
			$extensionConfiguration,
			$apiRegistry,
			$router,
			$tenantResolver,
			$this->createStub(LoginProviderInterface::class),
			$this->createStub(LogService::class)
		);
		$response = $middleware->process($request, $handler);

		$this->assertInstanceOf(JsonResponse::class, $response);
		$this->assertEquals(200, $response->getStatusCode());
		$this->assertEquals('{"status":"ok"}', (string) $response->getBody());
	}

	public function testProcessReturnsJsonResponseForHealthEndpointWithTrailingSlash(): void {
		$request = $this->createStub(ServerRequestInterface::class);
		$uri = $this->createStub(UriInterface::class);
		$uri->method('getPath')->willReturn('/api/health/');
		$request->method('getUri')->willReturn($uri);
		$request->method('getAttribute')->willReturn(NULL);
		$request->method('withAttribute')->willReturn($request);

		$handler = $this->createStub(RequestHandlerInterface::class);

		$extensionConfiguration = $this->createStub(ExtensionConfiguration::class);
		$extensionConfiguration->method('getApiPathPrefix')->willReturn('/api/');

		$apiRegistry = $this->createStub(ApiRegistry::class);
		$router = $this->createStub(Router::class);
		$tenantResolver = $this->createStub(TenantResolverInterface::class);
		$tenantResolver->method('resolve')->willReturn(
			TenantContextResult::success(new TenantContext('test-tenant'))
		);

		$middleware = new ApiRequestMiddleware(
			$extensionConfiguration,
			$apiRegistry,
			$router,
			$tenantResolver,
			$this->createStub(LoginProviderInterface::class),
			$this->createStub(LogService::class)
		);
		$response = $middleware->process($request, $handler);

		$this->assertInstanceOf(JsonResponse::class, $response);
		$this->assertEquals(200, $response->getStatusCode());
		$this->assertEquals('{"status":"ok"}', (string) $response->getBody());
	}

	public function testProcessCallsRouterForValidApiRequestWithTrailingSlash(): void {
		$request = $this->createStub(ServerRequestInterface::class);
		$request->method('getMethod')->willReturn('GET');
		$uri = $this->createStub(UriInterface::class);
		$uri->method('getPath')->willReturn('/api/public/v1/health/');
		$request->method('getUri')->willReturn($uri);
		$request->method('getAttribute')->willReturn(NULL);
		$request->method('withAttribute')->willReturn($request);

		$handler = $this->createStub(RequestHandlerInterface::class);

		$extensionConfiguration = $this->createStub(ExtensionConfiguration::class);
		$extensionConfiguration->method('getApiPathPrefix')->willReturn('/api/');

		$apiRegistry = $this->createStub(ApiRegistry::class);
		$apiRegistry->method('hasApi')->with('public')->willReturn(TRUE);
		$apiRegistry->method('getApi')->with('public')->willReturn(['versions' => ['1']]);
		$apiRegistry->method('getSecurityConfig')->willReturn(['authMode' => 'public']);

		$tenantResolver = $this->createStub(TenantResolverInterface::class);
		$tenantResolver->method('resolve')->willReturn(
			TenantContextResult::success(new TenantContext('test-tenant'))
		);

		$responseMock = new JsonResponse([], 200);
		$router = $this->createMock(Router::class);
		$router->expects($this->once())
			->method('dispatch')
			->willReturn($responseMock);

		$middleware = new ApiRequestMiddleware(
			$extensionConfiguration,
			$apiRegistry,
			$router,
			$tenantResolver,
			$this->createStub(LoginProviderInterface::class),
			$this->createStub(LogService::class)
		);
		$response = $middleware->process($request, $handler);

		$this->assertEquals(200, $response->getStatusCode());
	}

	public function testProcessDelegatesToHandlerForNonApiRequests(): void {
		$request = $this->createStub(ServerRequestInterface::class);
		$uri = $this->createStub(UriInterface::class);
		$uri->method('getPath')->willReturn('/some-other-path');
		$request->method('getUri')->willReturn($uri);

		$responseMock = new JsonResponse([], 200);
		$handler = $this->createMock(RequestHandlerInterface::class);
		$handler->expects($this->once())->method('handle')->with($request)->willReturn($responseMock);

		$extensionConfiguration = $this->createStub(ExtensionConfiguration::class);
		$extensionConfiguration->method('getApiPathPrefix')->willReturn('/api/');

		$apiRegistry = $this->createStub(ApiRegistry::class);
		$router = $this->createStub(Router::class);
		$tenantResolver = $this->createStub(TenantResolverInterface::class);

		$middleware = new ApiRequestMiddleware(
			$extensionConfiguration,
			$apiRegistry,
			$router,
			$tenantResolver,
			$this->createStub(LoginProviderInterface::class),
			$this->createStub(LogService::class)
		);
		$response = $middleware->process($request, $handler);

		$this->assertSame($responseMock, $response);
	}

	public function testProcessCallsRouterForValidApiRequest(): void {
		$request = $this->createStub(ServerRequestInterface::class);
		$request->method('getMethod')->willReturn('GET');
		$uri = $this->createStub(UriInterface::class);
		$uri->method('getPath')->willReturn('/api/public/v1/health');
		$request->method('getUri')->willReturn($uri);
		$request->method('getAttribute')->willReturn(NULL);
		$request->method('withAttribute')->willReturn($request);

		$handler = $this->createMock(RequestHandlerInterface::class);
		$handler->expects($this->never())->method('handle');

		$extensionConfiguration = $this->createStub(ExtensionConfiguration::class);
		$extensionConfiguration->method('getApiPathPrefix')->willReturn('/api/');

		$apiRegistry = $this->createStub(ApiRegistry::class);
		$apiRegistry->method('hasApi')->with('public')->willReturn(TRUE);
		$apiRegistry->method('getApi')->with('public')->willReturn(['versions' => ['1']]);
		$apiRegistry->method('getSecurityConfig')->willReturn(['authMode' => 'public']);

		$tenantResolver = $this->createStub(TenantResolverInterface::class);
		$tenantResolver->method('resolve')->willReturn(
			TenantContextResult::success(new TenantContext('test-tenant'))
		);

		$responseMock = new JsonResponse([], 200);
		$router = $this->createMock(Router::class);
		$router->expects($this->once())
			->method('dispatch')
			->willReturn($responseMock);

		$middleware = new ApiRequestMiddleware(
			$extensionConfiguration,
			$apiRegistry,
			$router,
			$tenantResolver,
			$this->createStub(LoginProviderInterface::class),
			$this->createStub(LogService::class)
		);
		$response = $middleware->process($request, $handler);

		$this->assertEquals(200, $response->getStatusCode());
	}

	public function testProcessReturns200ForPublicApiWithoutAuth(): void {
		$request = $this->createStub(ServerRequestInterface::class);
		$uri = $this->createStub(UriInterface::class);
		$uri->method('getPath')->willReturn('/api/public/v1/test');
		$request->method('getUri')->willReturn($uri);
		$request->method('getAttribute')->willReturn(NULL);
		$request->method('withAttribute')->willReturn($request);

		$handler = $this->createStub(RequestHandlerInterface::class);

		$extensionConfiguration = $this->createStub(ExtensionConfiguration::class);
		$extensionConfiguration->method('getApiPathPrefix')->willReturn('/api/');

		$apiRegistry = $this->createStub(ApiRegistry::class);
		$apiRegistry->method('hasApi')->with('public')->willReturn(TRUE);
		$apiRegistry->method('getApi')->with('public')->willReturn(['versions' => ['1']]);
		$apiRegistry->method('getSecurityConfig')->willReturn(['authMode' => 'public']);

		$tenantResolver = $this->createStub(TenantResolverInterface::class);
		$tenantResolver->method('resolve')->willReturn(
			TenantContextResult::success(new TenantContext('test-tenant'))
		);

		$request->method('withAttribute')->willReturn($request);

		$responseMock = new JsonResponse([], 200);
		$router = $this->createStub(Router::class);
		$router->method('dispatch')->willReturn($responseMock);

		$middleware = new ApiRequestMiddleware(
			$extensionConfiguration,
			$apiRegistry,
			$router,
			$tenantResolver,
			$this->createStub(LoginProviderInterface::class),
			$this->createStub(LogService::class)
		);
		$response = $middleware->process($request, $handler);

		$this->assertEquals(200, $response->getStatusCode());
	}

	public function testProcessReturns401WhenAuthRequiredButMissing(): void {
		$request = $this->createStub(ServerRequestInterface::class);
		$uri = $this->createStub(UriInterface::class);
		$uri->method('getPath')->willReturn('/api/partner/v1/test');
		$request->method('getUri')->willReturn($uri);
		$request->method('getAttribute')->willReturn(NULL);
		$request->method('withAttribute')->willReturn($request);

		$handler = $this->createStub(RequestHandlerInterface::class);

		$extensionConfiguration = $this->createStub(ExtensionConfiguration::class);
		$extensionConfiguration->method('getApiPathPrefix')->willReturn('/api/');

		$apiRegistry = $this->createStub(ApiRegistry::class);
		$apiRegistry->method('hasApi')->with('partner')->willReturn(TRUE);
		$apiRegistry->method('getApi')->with('partner')->willReturn(['versions' => ['1']]);
		$apiRegistry->method('getSecurityConfig')->willReturn(['authMode' => 'token']);

		$router = $this->createStub(Router::class);
		$tenantResolver = $this->createStub(TenantResolverInterface::class);
		$tenantResolver->method('resolve')->willReturn(
			TenantContextResult::success(new TenantContext('test-tenant'))
		);

		$loginProvider = $this->createStub(LoginProviderInterface::class);
		$loginProvider->method('authenticate')->willReturn(NULL);

		$middleware = new ApiRequestMiddleware(
			$extensionConfiguration,
			$apiRegistry,
			$router,
			$tenantResolver,
			$loginProvider,
			$this->createStub(LogService::class)
		);
		$response = $middleware->process($request, $handler);

		$this->assertEquals(401, $response->getStatusCode());
	}

	public function testProcessReturns404ForUnknownApi(): void {
		$request = $this->createStub(ServerRequestInterface::class);
		$uri = $this->createStub(UriInterface::class);
		$uri->method('getPath')->willReturn('/api/unknown/v1/health');
		$request->method('getUri')->willReturn($uri);
		$request->method('getAttribute')->willReturn(NULL);
		$request->method('withAttribute')->willReturn($request);

		$handler = $this->createStub(RequestHandlerInterface::class);

		$extensionConfiguration = $this->createStub(ExtensionConfiguration::class);
		$extensionConfiguration->method('getApiPathPrefix')->willReturn('/api/');

		$apiRegistry = $this->createStub(ApiRegistry::class);
		$apiRegistry->method('hasApi')->with('unknown')->willReturn(FALSE);

		$router = $this->createStub(Router::class);
		$tenantResolver = $this->createStub(TenantResolverInterface::class);
		$tenantResolver->method('resolve')->willReturn(
			TenantContextResult::success(new TenantContext('test-tenant'))
		);

		$middleware = new ApiRequestMiddleware(
			$extensionConfiguration,
			$apiRegistry,
			$router,
			$tenantResolver,
			$this->createStub(LoginProviderInterface::class),
			$this->createStub(LogService::class)
		);
		$response = $middleware->process($request, $handler);

		$this->assertEquals(404, $response->getStatusCode());
		$this->assertStringContainsString('application/problem+json', $response->getHeaderLine('Content-Type'));
	}

	public function testProcessReturnsErrorOnTenantResolutionFailure(): void {
		$request = $this->createStub(ServerRequestInterface::class);
		$uri = $this->createStub(UriInterface::class);
		$uri->method('getPath')->willReturn('/api/health');
		$request->method('getUri')->willReturn($uri);
		$request->method('getAttribute')->willReturn(NULL);
		$request->method('withAttribute')->willReturn($request);

		$handler = $this->createStub(RequestHandlerInterface::class);

		$extensionConfiguration = $this->createStub(ExtensionConfiguration::class);
		$extensionConfiguration->method('getApiPathPrefix')->willReturn('/api/');
		$extensionConfiguration->method('getOnMissingTenantStatusCode')->willReturn(400);

		$apiRegistry = $this->createStub(ApiRegistry::class);
		$router = $this->createStub(Router::class);
		$tenantResolver = $this->createStub(TenantResolverInterface::class);
		$tenantResolver->method('resolve')->willReturn(
			TenantContextResult::failure('site_not_found')
		);

		$middleware = new ApiRequestMiddleware(
			$extensionConfiguration,
			$apiRegistry,
			$router,
			$tenantResolver,
			$this->createStub(LoginProviderInterface::class),
			$this->createStub(LogService::class)
		);
		$response = $middleware->process($request, $handler);

		$this->assertEquals(400, $response->getStatusCode());
		$this->assertStringContainsString('Tenant Resolution Failed', (string) $response->getBody());
	}
}
