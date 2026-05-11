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

use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SGalinski\SgApiCore\Configuration\ExtensionConfiguration;
use SGalinski\SgApiCore\Context\TenantContext;
use SGalinski\SgApiCore\Middleware\ApiSetupMiddleware;
use SGalinski\SgApiCore\Service\LogService;
use SGalinski\SgApiCore\Service\PathAnalysisService;
use SGalinski\SgApiCore\Service\ResponseService;
use SGalinski\SgApiCore\Service\Tenant\TenantContextResult;
use SGalinski\SgApiCore\Service\Tenant\TenantResolverInterface;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Test case for ApiSetupMiddleware
 */
class ApiSetupMiddlewareTest extends UnitTestCase {
	protected bool $resetSingletonInstances = TRUE;
	protected $extensionConfiguration;
	protected $tenantResolver;
	protected $pathAnalysisService;
	protected $logService;
	protected $responseService;
	protected $context;
	protected $middleware;

	protected function setUp(): void {
		parent::setUp();
		$this->extensionConfiguration = $this->createStub(ExtensionConfiguration::class);
		$this->extensionConfiguration->method('getApiPathPrefix')->willReturn('/api');

		$this->tenantResolver = $this->createStub(TenantResolverInterface::class);
		$this->pathAnalysisService = $this->createStub(PathAnalysisService::class);
		$this->logService = $this->createStub(LogService::class);
		$this->responseService = $this->createStub(ResponseService::class);
		$this->context = $this->createStub(Context::class);

		$this->middleware = new ApiSetupMiddleware(
			$this->extensionConfiguration,
			$this->tenantResolver,
			$this->pathAnalysisService,
			$this->logService,
			$this->responseService,
			$this->context
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

		$language = $this->createStub(SiteLanguage::class);
		$base = $this->createStub(UriInterface::class);
		$base->method('getPath')->willReturn('/en/');
		$language->method('getBase')->willReturn($base);

		$request->method('getAttribute')->willReturnMap([['language', $language], ]);

		$tenantResult = TenantContextResult::success(new TenantContext('test'));
		$this->tenantResolver->method('resolve')->willReturn($tenantResult);

		$request->method('withAttribute')->willReturnSelf();

		$handler = $this->createStub(RequestHandlerInterface::class);
		$response = $this->createStub(ResponseInterface::class);
		$response->method('withHeader')->willReturnSelf();
		$handler->method('handle')->willReturn($response);

		$result = $this->middleware->process($request, $handler);
		$this->assertSame($response, $result);
	}

	/**
	 * @test
	 */
	public function testProcessSetsTenantAttribute(): void {
		$request = $this->createStub(ServerRequestInterface::class);
		$uri = $this->createStub(UriInterface::class);
		$uri->method('getPath')->willReturn('/api/test/v1/foo');
		$request->method('getUri')->willReturn($uri);

		$tenantResult = TenantContextResult::success(new TenantContext('test'));
		$this->tenantResolver->method('resolve')->willReturn($tenantResult);

		$request->method('withAttribute')->willReturnSelf();

		$handler = $this->createStub(RequestHandlerInterface::class);
		$response = $this->createStub(ResponseInterface::class);
		$response->method('withHeader')->willReturnSelf();
		$handler->method('handle')->willReturn($response);

		$result = $this->middleware->process($request, $handler);
		$this->assertSame($response, $result);
	}
}
