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
use SGalinski\SgApiCore\Context\TenantContext;
use SGalinski\SgApiCore\Middleware\ApiAuthMiddleware;
use SGalinski\SgApiCore\Security\AuthContext;
use SGalinski\SgApiCore\Security\LoginProviderInterface;
use SGalinski\SgApiCore\Service\ApiRegistry;
use SGalinski\SgApiCore\Service\PathAnalysisService;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Test case for ApiAuthMiddleware
 */
class ApiAuthMiddlewareTest extends UnitTestCase {
	protected $apiRegistry;
	protected $loginProvider;
	protected $pathAnalysisService;
	protected $middleware;

	protected function setUp(): void {
		parent::setUp();
		$this->apiRegistry = $this->createStub(ApiRegistry::class);
		$this->loginProvider = $this->createMock(LoginProviderInterface::class);
		$this->pathAnalysisService = $this->createStub(PathAnalysisService::class);

		$this->middleware = new ApiAuthMiddleware($this->apiRegistry, $this->loginProvider, $this->pathAnalysisService);
	}

	/**
	 * @test
	 */
	public function testProcessCallsLoginProvider(): void {
		$request = $this->createStub(ServerRequestInterface::class);
		$uri = $this->createStub(UriInterface::class);
		$uri->method('getPath')->willReturn('/api/test/v1/foo');
		$request->method('getUri')->willReturn($uri);
		$request->method('getAttribute')->willReturnCallback(static function ($name) {
			if ($name === 'api.id') {
				return 'test';
			}
			if ($name === 'api.version') {
				return '1';
			}
			if ($name === 'api.tenant') {
				return new TenantContext('tenant');
			}
			return NULL;
		});
		$request->method('hasHeader')->willReturn(FALSE);
		$request->method('withAttribute')->willReturnSelf();

		$this->apiRegistry->method('hasApi')->with('test')->willReturn(TRUE);
		$this->apiRegistry->method('getApi')->with('test')->willReturn(['versions' => ['1']]);
		$this->apiRegistry->method('getSecurityConfig')->willReturn(['authProviders' => []]);

		$authContext = new AuthContext('test', 'tenant');
		$this->loginProvider->expects($this->once())
			->method('authenticate')
			->willReturn($authContext);

		$handler = $this->createStub(RequestHandlerInterface::class);
		$handler->method('handle')->willReturn($this->createStub(ResponseInterface::class));

		$this->middleware->process($request, $handler);
	}
}
