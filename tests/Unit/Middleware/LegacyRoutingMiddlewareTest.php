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

declare(strict_types=1);

namespace SGalinski\SgApiCore\Tests\Unit\Middleware;

use PHPUnit\Framework\MockObject\MockObject;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SGalinski\SgApiCore\Configuration\ExtensionConfiguration;
use SGalinski\SgApiCore\Middleware\LegacyRoutingMiddleware;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

class LegacyRoutingMiddlewareTest extends UnitTestCase {
	/**
	 * @var ExtensionConfiguration|MockObject
	 */
	protected $extensionConfiguration;

	/**
	 * @var RequestHandlerInterface|MockObject
	 */
	protected $handler;

	/**
	 * @var LegacyRoutingMiddleware
	 */
	protected $middleware;

	protected function setUp(): void {
		parent::setUp();
		$this->extensionConfiguration = $this->createStub(ExtensionConfiguration::class);
		$this->extensionConfiguration->method('getApiPathPrefix')->willReturn('/api/');
		$this->extensionConfiguration->method('isActivateLegacySupport')->willReturn(TRUE);
		$this->handler = $this->createMock(RequestHandlerInterface::class);
		$this->middleware = new LegacyRoutingMiddleware($this->extensionConfiguration);
	}

	public function testProcessMapsQueryParamLegacyRequest(): void {
		$uri = $this->createStub(UriInterface::class);
		$uri->method('getPath')->willReturn('/');
		$uri->method('withPath')->with('/api/legacy/v1/auth/legacyLogin')->willReturn($uri);

		$request = $this->createMock(ServerRequestInterface::class);
		$request->method('getUri')->willReturn($uri);
		$request->method('getQueryParams')->willReturn([
			'type' => '1595576052',
			'tx_sgrest' => [
				'request' => 'authentication/authentication/getBearerToken'
			]
		]);
		$request->method('hasHeader')->with('Authorization')->willReturn(FALSE);

		$request->expects($this->exactly(2))
			->method('withAttribute')
			->willReturnMap([
				['api.isLegacy', TRUE, $request],
				['api.legacyApiKey', 'authentication', $request],
			]);
		$request->method('withUri')->willReturn($request);

		$this->handler->expects($this->once())
			->method('handle')
			->with($request)
			->willReturn($this->createStub(ResponseInterface::class));

		$this->middleware->process($request, $this->handler);
	}

	public function testProcessMapsPathBasedLegacyRequest(): void {
		$uri = $this->createStub(UriInterface::class);
		$uri->method('getPath')->willReturn('/my-key/news/1/get');
		$uri->method('withPath')->with('/api/legacy/v1/my-key/news/1/get')->willReturn($uri);

		$request = $this->createStub(ServerRequestInterface::class);
		$request->method('getUri')->willReturn($uri);
		$request->method('getQueryParams')->willReturn(['type' => '1595576052']);
		$request->method('hasHeader')->with('Authorization')->willReturn(FALSE);

		$request->method('withUri')->willReturn($request);
		$request->method('withAttribute')->willReturn($request);

		$this->handler->expects($this->once())
			->method('handle')
			->with($request)
			->willReturn($this->createStub(ResponseInterface::class));

		$this->middleware->process($request, $this->handler);
	}

	public function testProcessMapsPathBasedLegacyRequestWithLanguagePrefix(): void {
		$uri = $this->createStub(UriInterface::class);
		$uri->method('getPath')->willReturn('/en/my-key/news/1/get');
		$uri->method('withPath')->with('/en/api/legacy/v1/my-key/news/1/get')->willReturn($uri);

		$request = $this->createStub(ServerRequestInterface::class);
		$request->method('getUri')->willReturn($uri);
		$request->method('getQueryParams')->willReturn(['type' => '1595576052']);
		$request->method('hasHeader')->with('Authorization')->willReturn(FALSE);

		$language = $this->createStub(\TYPO3\CMS\Core\Site\Entity\SiteLanguage::class);
		$base = $this->createStub(\Psr\Http\Message\UriInterface::class);
		$base->method('getPath')->willReturn('/en/');
		$language->method('getBase')->willReturn($base);

		$request->method('getAttribute')->willReturnMap([
			['language', $language],
		]);

		$request->method('withUri')->willReturn($request);
		$request->method('withAttribute')->willReturn($request);

		$this->handler->expects($this->once())
			->method('handle')
			->with($request)
			->willReturn($this->createStub(ResponseInterface::class));

		$this->middleware->process($request, $this->handler);
	}

	public function testProcessDelegatesNormalRequest(): void {
		$uri = $this->createStub(UriInterface::class);
		$uri->method('getPath')->willReturn('/some/normal/page');

		$request = $this->createMock(ServerRequestInterface::class);
		$request->method('getUri')->willReturn($uri);
		$request->method('getQueryParams')->willReturn([]);

		$request->expects($this->never())->method('withUri');
		$request->expects($this->never())->method('withAttribute');

		$this->handler->expects($this->once())
			->method('handle')
			->with($request)
			->willReturn($this->createStub(ResponseInterface::class));

		$this->middleware->process($request, $this->handler);
	}

	public function testProcessSkipsReservedPaths(): void {
		$uri = $this->createStub(UriInterface::class);
		$uri->method('getPath')->willReturn('/typo3/index.php');

		$request = $this->createMock(ServerRequestInterface::class);
		$request->method('getUri')->willReturn($uri);
		$request->method('getQueryParams')->willReturn([]);

		$request->expects($this->never())->method('withUri');

		$this->handler->expects($this->once())
			->method('handle')
			->with($request)
			->willReturn($this->createStub(ResponseInterface::class));

		$this->middleware->process($request, $this->handler);
	}

	public function testProcessDoesNothingWhenLegacySupportIsDisabled(): void {
		$extensionConfiguration = $this->createStub(ExtensionConfiguration::class);
		$extensionConfiguration->method('isActivateLegacySupport')->willReturn(FALSE);
		$middleware = new LegacyRoutingMiddleware($extensionConfiguration);

		$request = $this->createMock(ServerRequestInterface::class);
		// If legacy support is disabled, it shouldn't even check the URI or query params
		$request->expects($this->never())->method('getUri');

		$this->handler->expects($this->once())
			->method('handle')
			->with($request)
			->willReturn($this->createStub(ResponseInterface::class));

		$middleware->process($request, $this->handler);
	}
}
