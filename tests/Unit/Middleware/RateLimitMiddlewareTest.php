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
use Psr\Http\Server\RequestHandlerInterface;
use SGalinski\SgApiCore\Configuration\ExtensionConfiguration;
use SGalinski\SgApiCore\Context\TenantContext;
use SGalinski\SgApiCore\Middleware\RateLimitMiddleware;
use SGalinski\SgApiCore\Service\ApiRegistry;
use SGalinski\SgApiCore\Service\LogService;
use SGalinski\SgApiCore\Service\RateLimitService;
use SGalinski\SgApiCore\Service\ResourceRegistry;
use SGalinski\SgApiCore\Service\ResponseService;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Test case for RateLimitMiddleware
 */
class RateLimitMiddlewareTest extends UnitTestCase {
	public function testBlocksWhenRateLimitExceeded(): void {
		$config = $this->createStub(ExtensionConfiguration::class);
		$config->method('isRateLimitEnabled')->willReturn(TRUE);
		$config->method('getRateLimitDefaultLimit')->willReturn(2);
		$config->method('getRateLimitWindowSeconds')->willReturn(60);

		$rateLimitService = $this->createStub(RateLimitService::class);
		$rateLimitService->method('consume')->willReturn([
			'allowed' => FALSE,
			'limit' => 2,
			'remaining' => 0,
			'reset' => time() + 60,
			'burst' => 0
		]);

		$apiRegistry = $this->createStub(ApiRegistry::class);
		$resourceRegistry = $this->createStub(ResourceRegistry::class);
		$responseService = $this->createStub(ResponseService::class);
		$responseService->method('createErrorResponse')->willReturn(new JsonResponse(['status' => 429], 429));
		$logService = $this->createStub(LogService::class);

		$middleware = new RateLimitMiddleware(
			$config,
			$apiRegistry,
			$resourceRegistry,
			$rateLimitService,
			$responseService,
			$logService
		);

		$request = $this->createStub(ServerRequestInterface::class);
		$request->method('getAttribute')->willReturnCallback(static function ($name) {
			if ($name === 'api.id') {
				return 'public';
			}
			if ($name === 'api.tenant') {
				return new TenantContext('tenant-1');
			}
			return NULL;
		});
		$request->method('getHeaderLine')->willReturn('');
		$request->method('getServerParams')->willReturn(['REMOTE_ADDR' => '127.0.0.1']);

		$handler = $this->createStub(RequestHandlerInterface::class);

		$response = $middleware->process($request, $handler);

		$this->assertInstanceOf(JsonResponse::class, $response);
		$this->assertSame(429, $response->getStatusCode());
	}

	public function testAddsRateLimitHeadersOnSuccess(): void {
		$config = $this->createStub(ExtensionConfiguration::class);
		$config->method('isRateLimitEnabled')->willReturn(TRUE);
		$config->method('getRateLimitDefaultLimit')->willReturn(2);
		$config->method('getRateLimitWindowSeconds')->willReturn(60);

		$rateLimitService = $this->createStub(RateLimitService::class);
		$rateLimitService->method('consume')->willReturn([
			'allowed' => TRUE,
			'limit' => 2,
			'remaining' => 1,
			'reset' => time() + 60,
			'burst' => 0
		]);

		$apiRegistry = $this->createStub(ApiRegistry::class);
		$resourceRegistry = $this->createStub(ResourceRegistry::class);
		$responseService = $this->createStub(ResponseService::class);
		$logService = $this->createStub(LogService::class);

		$middleware = new RateLimitMiddleware(
			$config,
			$apiRegistry,
			$resourceRegistry,
			$rateLimitService,
			$responseService,
			$logService
		);

		$request = $this->createStub(ServerRequestInterface::class);
		$request->method('getAttribute')->willReturnCallback(static function ($name) {
			if ($name === 'api.id') {
				return 'public';
			}
			if ($name === 'api.tenant') {
				return new TenantContext('tenant-1');
			}
			return NULL;
		});
		$request->method('getHeaderLine')->willReturn('');
		$request->method('getServerParams')->willReturn(['REMOTE_ADDR' => '127.0.0.1']);
		$request->method('withAttribute')->willReturnSelf();

		$handlerResponse = new JsonResponse(['ok' => TRUE], 200);
		$handler = $this->createStub(RequestHandlerInterface::class);
		$handler->method('handle')->willReturn($handlerResponse);

		$response = $middleware->process($request, $handler);

		$this->assertInstanceOf(ResponseInterface::class, $response);
		$this->assertSame('2', $response->getHeaderLine('X-RateLimit-Limit'));
		$this->assertSame('1', $response->getHeaderLine('X-RateLimit-Remaining'));
		$this->assertNotEmpty($response->getHeaderLine('X-RateLimit-Reset'));
	}

	public function testUsesResourceRateLimitOverrides(): void {
		$config = $this->createStub(ExtensionConfiguration::class);
		$config->method('isRateLimitEnabled')->willReturn(TRUE);
		$config->method('getRateLimitDefaultLimit')->willReturn(10);
		$config->method('getRateLimitWindowSeconds')->willReturn(60);
		$config->method('getRateLimitDefaultBurst')->willReturn(0);

		$apiRegistry = $this->createStub(ApiRegistry::class);
		$apiRegistry->method('getRateLimitConfig')->willReturn([
			'limit' => 5,
			'windowSeconds' => 60
		]);

		$resourceRegistry = $this->createStub(ResourceRegistry::class);
		$resourceRegistry->method('getResources')->willReturn([
			[
				'basePath' => '/items',
				'rateLimit' => [
					'limit' => 1,
					'windowSeconds' => 30,
					'burst' => 2
				]
			]
		]);

		$rateLimitService = $this->createMock(RateLimitService::class);
		$rateLimitService->expects($this->once())->method('consume')->with(
			'public:tenant-1:ip:127.0.0.1',
			1,
			30,
			2
		)->willReturn([
			'allowed' => TRUE,
			'limit' => 3,
			'remaining' => 2,
			'reset' => time() + 30,
			'burst' => 2
		]);

		$responseService = $this->createStub(ResponseService::class);
		$logService = $this->createStub(LogService::class);

		$middleware = new RateLimitMiddleware(
			$config,
			$apiRegistry,
			$resourceRegistry,
			$rateLimitService,
			$responseService,
			$logService
		);

		$request = $this->createStub(ServerRequestInterface::class);
		$request->method('getAttribute')->willReturnCallback(static function ($name) {
			if ($name === 'api.id') {
				return 'public';
			}
			if ($name === 'api.tenant') {
				return new TenantContext('tenant-1');
			}
			if ($name === 'api.remainingPath') {
				return '/items/10';
			}
			return NULL;
		});
		$request->method('getHeaderLine')->willReturn('');
		$request->method('getServerParams')->willReturn(['REMOTE_ADDR' => '127.0.0.1']);
		$request->method('withAttribute')->willReturnSelf();

		$handlerResponse = new JsonResponse(['ok' => TRUE], 200);
		$handler = $this->createStub(RequestHandlerInterface::class);
		$handler->method('handle')->willReturn($handlerResponse);

		$response = $middleware->process($request, $handler);

		$this->assertSame('3', $response->getHeaderLine('X-RateLimit-Limit'));
		$this->assertSame('2', $response->getHeaderLine('X-RateLimit-Remaining'));
		$this->assertSame('2', $response->getHeaderLine('X-RateLimit-Burst'));
	}
}
