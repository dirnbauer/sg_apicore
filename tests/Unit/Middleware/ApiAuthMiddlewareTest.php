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
use SGalinski\SgApiCore\Middleware\ApiAuthMiddleware;
use SGalinski\SgApiCore\Security\AuthContext;
use SGalinski\SgApiCore\Security\LoginProviderInterface;
use SGalinski\SgApiCore\Service\ApiRegistry;
use SGalinski\SgApiCore\Service\LogService;
use SGalinski\SgApiCore\Service\PathAnalysisService;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Test case for ApiAuthMiddleware
 */
class ApiAuthMiddlewareTest extends UnitTestCase {
	protected $apiRegistry;
	protected $loginProvider;
	protected $pathAnalysisService;
	protected $logService;
	protected $middleware;

	protected function setUp(): void {
		parent::setUp();
		$this->apiRegistry = $this->createStub(ApiRegistry::class);
		$this->loginProvider = $this->createMock(LoginProviderInterface::class);
		$this->pathAnalysisService = $this->createStub(PathAnalysisService::class);
		$this->logService = $this->createStub(LogService::class);

		$this->middleware = new ApiAuthMiddleware(
			$this->apiRegistry,
			$this->loginProvider,
			$this->pathAnalysisService,
			$this->logService
		);
	}

	/**
	 * @test
	 */
	public function testProcessCallsLoginProvider(): void {
		$request = $this->createStub(ServerRequestInterface::class);
		$uri = $this->createStub(UriInterface::class);
		$uri->method('getPath')->willReturn('/api/test/v1/foo');
		$request->method('getUri')->willReturn($uri);
		$request->method('getAttribute')->willReturnMap([
			['api.id', 'test'],
			['api.version', '1'],
			['api.tenant', new \SGalinski\SgApiCore\Context\TenantContext('tenant')],
		]);
		$request->method('withAttribute')->willReturnSelf();

		$this->apiRegistry->method('hasApi')->with('test')->willReturn(TRUE);
		$this->apiRegistry->method('getApi')->with('test')->willReturn(['versions' => ['1']]);

		$authContext = new AuthContext('test', 'tenant');
		$this->loginProvider->expects($this->once())
			->method('authenticate')
			->willReturn($authContext);

		$handler = $this->createStub(RequestHandlerInterface::class);
		$handler->method('handle')->willReturn($this->createStub(ResponseInterface::class));

		$this->middleware->process($request, $handler);
	}
}
