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
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SGalinski\SgApiCore\Configuration\ExtensionConfiguration;
use SGalinski\SgApiCore\Middleware\LegacyRoutingMiddleware;
use TYPO3\CMS\Core\Http\ServerRequest;
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
		$request = new ServerRequest('https://example.com/', 'GET', 'php://input', [], [
			'type' => '1595576052',
			'tx_sgrest' => [
				'request' => 'authentication/authentication/getBearerToken',
			],
		]);

		$this->handler->expects($this->once())
			->method('handle')
			->willReturn($this->createStub(ResponseInterface::class));

		$this->middleware->process($request, $this->handler);
	}

	public function testProcessMapsPathBasedLegacyRequest(): void {
		$request = new ServerRequest('https://example.com/my-key/news/1/get', 'GET', 'php://input', [], [
			'type' => '1595576052',
		]);

		$this->handler->expects($this->once())
			->method('handle')
			->willReturn($this->createStub(ResponseInterface::class));

		$this->middleware->process($request, $this->handler);
	}

	public function testProcessMapsPathBasedLegacyRequestWithLanguagePrefix(): void {
		$request = new ServerRequest('https://example.com/en/my-key/news/1/get', 'GET', 'php://input', [], [
			'type' => '1595576052',
		]);

		$language = $this->createStub(SiteLanguage::class);
		$base = $this->createStub(UriInterface::class);
		$base->method('getPath')->willReturn('/en/');
		$language->method('getBase')->willReturn($base);

		$request = $request->withAttribute('language', $language);

		$this->handler->expects($this->once())
			->method('handle')
			->willReturn($this->createStub(ResponseInterface::class));

		$this->middleware->process($request, $this->handler);
	}

	public function testProcessDelegatesNormalRequest(): void {
		$uri = $this->createStub(UriInterface::class);
		$uri->method('getPath')->willReturn('/some/normal/page');

		$request = $this->createMock(ServerRequestInterface::class);
		$request->method('getUri')->willReturn($uri);
		$request->method('getQueryParams')->willReturn([]);
		$request->method('hasHeader')->willReturn(FALSE);

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
		$request->method('hasHeader')->willReturn(FALSE);

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
		$request->method('hasHeader')->willReturn(FALSE);
		// If legacy support is disabled, it shouldn't even check the URI or query params
		$request->expects($this->never())->method('getUri');

		$this->handler->expects($this->once())
			->method('handle')
			->with($request)
			->willReturn($this->createStub(ResponseInterface::class));

		$middleware->process($request, $this->handler);
	}
}
