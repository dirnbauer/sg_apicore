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
use SGalinski\SgApiCore\Context\TenantContext;
use SGalinski\SgApiCore\Middleware\ApiSetupMiddleware;
use SGalinski\SgApiCore\Service\LogService;
use SGalinski\SgApiCore\Service\PathAnalysisService;
use SGalinski\SgApiCore\Service\Tenant\TenantContextResult;
use SGalinski\SgApiCore\Service\Tenant\TenantResolverInterface;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Test case for ApiSetupMiddleware
 */
class ApiSetupMiddlewareTest extends UnitTestCase {
	protected $extensionConfiguration;
	protected $tenantResolver;
	protected $pathAnalysisService;
	protected $logService;
	protected $middleware;

	protected function setUp(): void {
		parent::setUp();
		$this->extensionConfiguration = $this->createStub(ExtensionConfiguration::class);
		$this->extensionConfiguration->method('getApiPathPrefix')->willReturn('/api');

		$this->tenantResolver = $this->createStub(TenantResolverInterface::class);
		$this->pathAnalysisService = $this->createStub(PathAnalysisService::class);
		$this->logService = $this->createStub(LogService::class);

		$this->middleware = new ApiSetupMiddleware(
			$this->extensionConfiguration,
			$this->tenantResolver,
			$this->pathAnalysisService,
			$this->logService
		);
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
