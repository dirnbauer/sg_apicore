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

use PHPUnit\Framework\MockObject\MockObject;
use Psr\Http\Server\RequestHandlerInterface;
use SGalinski\SgApiCore\Configuration\ExtensionConfiguration;
use SGalinski\SgApiCore\Middleware\ApiCorsMiddleware;
use SGalinski\SgApiCore\Service\ApiRegistry;
use SGalinski\SgApiCore\Service\PathAnalysisService;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

class ApiCorsMiddlewareTest extends UnitTestCase {
	protected ExtensionConfiguration|MockObject $extensionConfiguration;
	protected ApiRegistry|MockObject $apiRegistry;
	protected PathAnalysisService|MockObject $pathAnalysisService;
	protected ApiCorsMiddleware $middleware;

	protected function setUp(): void {
		parent::setUp();
		$this->extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
		$this->apiRegistry = $this->createMock(ApiRegistry::class);
		$this->pathAnalysisService = $this->createMock(PathAnalysisService::class);
		$this->extensionConfiguration
			->method('getApiPathPrefix')
			->willReturn('/api/');
		$this->middleware = new ApiCorsMiddleware(
			$this->extensionConfiguration,
			$this->apiRegistry,
			$this->pathAnalysisService
		);
	}

	/**
	 * @test
	 */
	public function processReturnsPreflightResponseForAllowedApiOrigin(): void {
		$request = (new ServerRequest('/api/partner/v1/offer', 'OPTIONS'))
			->withHeader('Origin', 'https://app.example.org')
			->withHeader('Access-Control-Request-Method', 'GET')
			->withHeader('Access-Control-Request-Headers', 'authorization, cache-control');

		$handler = $this->createMock(RequestHandlerInterface::class);
		$handler->expects($this->never())->method('handle');
		$this->pathAnalysisService->expects($this->once())
			->method('analyze')
			->with('/api/partner/v1/offer')
			->willReturn([
				'apiId' => 'partner',
				'version' => '1',
				'remainingPath' => '/offer',
			]);
		$this->apiRegistry->expects($this->once())
			->method('hasApi')
			->with('partner')
			->willReturn(TRUE);
		$this->apiRegistry->expects($this->once())
			->method('getSecurityConfig')
			->with('partner', '1')
			->willReturn([
				'cors' => [
					'allowedOrigins' => ['https://app.example.org'],
					'allowCredentials' => TRUE,
				],
			]);

		$response = $this->middleware->process($request, $handler);

		$this->assertSame(204, $response->getStatusCode());
		$this->assertSame('https://app.example.org', $response->getHeaderLine('Access-Control-Allow-Origin'));
		$this->assertSame('true', $response->getHeaderLine('Access-Control-Allow-Credentials'));
		$this->assertStringContainsString('Origin', $response->getHeaderLine('Vary'));
	}

	/**
	 * @test
	 */
	public function processReturnsForbiddenForDisallowedPreflightOrigin(): void {
		$request = (new ServerRequest('/api/partner/v1/offer', 'OPTIONS'))
			->withHeader('Origin', 'https://blocked.example.org')
			->withHeader('Access-Control-Request-Method', 'GET');

		$handler = $this->createMock(RequestHandlerInterface::class);
		$handler->expects($this->never())->method('handle');
		$this->pathAnalysisService->expects($this->once())
			->method('analyze')
			->with('/api/partner/v1/offer')
			->willReturn([
				'apiId' => 'partner',
				'version' => '1',
				'remainingPath' => '/offer',
			]);
		$this->apiRegistry->expects($this->once())
			->method('hasApi')
			->with('partner')
			->willReturn(TRUE);
		$this->apiRegistry->expects($this->once())
			->method('getSecurityConfig')
			->with('partner', '1')
			->willReturn([
				'cors' => [
					'allowedOrigins' => ['https://app.example.org'],
					'allowCredentials' => FALSE,
				],
			]);

		$response = $this->middleware->process($request, $handler);

		$this->assertSame(403, $response->getStatusCode());
		$this->assertSame('Origin', $response->getHeaderLine('Vary'));
	}

	/**
	 * @test
	 */
	public function processAddsCorsHeadersToAllowedApiResponse(): void {
		$request = (new ServerRequest('/api/partner/v1/offer', 'GET'))
			->withHeader('Origin', 'https://app.example.org');

		$handlerResponse = new Response(NULL, 200, ['X-Test' => 'ok']);
		$handler = $this->createMock(RequestHandlerInterface::class);
		$handler->expects($this->once())
			->method('handle')
			->with($request)
			->willReturn($handlerResponse);
		$this->pathAnalysisService->expects($this->once())
			->method('analyze')
			->with('/api/partner/v1/offer')
			->willReturn([
				'apiId' => 'partner',
				'version' => '1',
				'remainingPath' => '/offer',
			]);
		$this->apiRegistry->expects($this->once())
			->method('hasApi')
			->with('partner')
			->willReturn(TRUE);
		$this->apiRegistry->expects($this->once())
			->method('getSecurityConfig')
			->with('partner', '1')
			->willReturn([
				'cors' => [
					'allowedOrigins' => ['https://app.example.org'],
					'allowCredentials' => TRUE,
				],
			]);

		$response = $this->middleware->process($request, $handler);

		$this->assertSame('ok', $response->getHeaderLine('X-Test'));
		$this->assertSame('https://app.example.org', $response->getHeaderLine('Access-Control-Allow-Origin'));
		$this->assertSame('true', $response->getHeaderLine('Access-Control-Allow-Credentials'));
	}

	/**
	 * @test
	 */
	public function processPassesThroughForNonApiPath(): void {
		$request = (new ServerRequest('/kontakt', 'GET'))
			->withHeader('Origin', 'https://app.example.org');

		$handlerResponse = new Response(NULL, 200, ['X-Test' => 'ok']);
		$handler = $this->createMock(RequestHandlerInterface::class);
		$handler->expects($this->once())
			->method('handle')
			->with($request)
			->willReturn($handlerResponse);
		$this->pathAnalysisService->expects($this->never())->method('analyze');
		$this->apiRegistry->expects($this->never())->method('hasApi');

		$response = $this->middleware->process($request, $handler);

		$this->assertSame('ok', $response->getHeaderLine('X-Test'));
		$this->assertSame('', $response->getHeaderLine('Access-Control-Allow-Origin'));
	}
}
