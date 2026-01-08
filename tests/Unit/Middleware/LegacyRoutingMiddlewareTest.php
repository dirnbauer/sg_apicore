<?php

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
}
