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

namespace SGalinski\SgApiCore\Tests\Unit\Controller;

use PHPUnit\Framework\MockObject\MockObject;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use SGalinski\SgApiCore\Attribute\ApiLegacyMode;
use SGalinski\SgApiCore\Context\TenantContext;
use SGalinski\SgApiCore\Controller\UserAuthController;
use SGalinski\SgApiCore\Domain\Repository\TokenRepository;
use SGalinski\SgApiCore\Service\ApiRegistry;
use SGalinski\SgApiCore\Service\ResponseService;
use SGalinski\SgApiCore\Service\TokenService;
use SGalinski\SgApiCore\Service\UserAuthService;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Test case for UserAuthController
 */
#[\PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations]
class UserAuthControllerTest extends UnitTestCase {
	/**
	 * @var UserAuthController
	 */
	protected UserAuthController $controller;

	/**
	 * @var TokenRepository|MockObject
	 */
	protected $tokenRepository;

	/**
	 * @var TokenService|MockObject
	 */
	protected $tokenService;

	/**
	 * @var ApiRegistry|MockObject
	 */
	protected $apiRegistry;

	/**
	 * @var ResponseService|MockObject
	 */
	protected $responseService;

	/**
	 * @var UserAuthService|MockObject
	 */
	protected $userAuthService;

	protected function setUp(): void {
		parent::setUp();
		$this->tokenRepository = $this->createMock(TokenRepository::class);
		$this->tokenService = $this->createMock(TokenService::class);
		$this->apiRegistry = $this->createMock(ApiRegistry::class);
		$this->responseService = $this->createMock(ResponseService::class);
		$this->userAuthService = $this->createMock(UserAuthService::class);

		$this->controller = new UserAuthController(
			$this->tokenRepository,
			$this->tokenService,
			$this->apiRegistry,
			$this->responseService,
			$this->userAuthService
		);
	}

	public function testLoginSuccessful(): void {
		$request = $this->createMock(ServerRequestInterface::class);
		$request->method('getParsedBody')->willReturn(['username' => 'testuser', 'password' => 'testpass']);
		$tenantContext = new TenantContext('test-tenant');
		$request->method('getAttribute')->willReturnMap([
			['api.tenant', $tenantContext],
			['api.id', 'public'],
			['api.version', '1'],
		]);

		$userRecord = [
			'uid' => 123,
			'username' => 'testuser',
			'password' => 'hashed-password',
		];
		$this->userAuthService->method('authenticateUser')->willReturn($userRecord);

		$tokens = [
			'access_token' => 'access-token',
			'refresh_token' => 'refresh-token',
			'token_type' => 'Bearer',
			'expires_in' => 3600,
		];
		$this->userAuthService->method('generateTokensForUserWithScopeHandling')->willReturn($tokens);

		$this->responseService->method('createSuccessResponse')->willReturnCallback(
			fn ($data) => new JsonResponse($data)
		);

		$response = $this->controller->login($request);

		$this->assertInstanceOf(JsonResponse::class, $response);
		$this->assertEquals(200, $response->getStatusCode());
		$data = json_decode((string) $response->getBody(), TRUE);
		$this->assertEquals('access-token', $data['access_token']);
		$this->assertEquals('refresh-token', $data['refresh_token']);
	}

	public function testLoginInheritsScopesFromBearerFallback(): void {
		$request = $this->createMock(ServerRequestInterface::class);
		$request->method('getParsedBody')->willReturn(['username' => 'testuser', 'password' => 'testpass']);
		$tenantContext = new TenantContext('test-tenant', 'test-site', 1);
		$request->method('getAttribute')->willReturnMap([
			['api.tenant', NULL, $tenantContext],
			['api.id', NULL, 'public'],
			['api.version', NULL, '1'],
			['api.auth', NULL, NULL],
		]);
		$request->method('getHeaderLine')->with('Authorization')->willReturn('Bearer valid-token');

		$userRecord = ['uid' => 123, 'username' => 'testuser'];
		$this->userAuthService->method('authenticateUser')->willReturn($userRecord);

		// Verify that the controller delegates scope handling to the dedicated service method.
		$this->userAuthService->expects($this->once())
			->method('generateTokensForUserWithScopeHandling')
			->with($userRecord, $request, 'public', '1')
			->willReturn(['access_token' => 'new-token']);

		$this->responseService->method('createSuccessResponse')->willReturn(new JsonResponse([]));

		$this->controller->login($request);
	}

	public function testLoginInvalidCredentials(): void {
		$request = $this->createMock(ServerRequestInterface::class);
		$request->method('getParsedBody')->willReturn(['username' => 'testuser', 'password' => 'wrongpass']);

		$this->userAuthService->method('authenticateUser')->willReturn(NULL);

		$this->responseService->method('createErrorResponse')->willReturnCallback(
			fn ($title, $detail, $status) => new JsonResponse(['title' => $title], $status)
		);

		$response = $this->controller->login($request);

		$this->assertEquals(401, $response->getStatusCode());
	}

	public function testRefreshSuccessful(): void {
		$request = $this->createMock(ServerRequestInterface::class);
		$request->method('getParsedBody')->willReturn(['refresh_token' => 'valid-refresh-token']);
		$request->method('getAttribute')->willReturnMap([
			['api.tenant', NULL, new TenantContext('test-tenant')],
			['api.id', NULL, 'public'],
			['api.version', NULL, '1'],
		]);

		$tokens = [
			'access_token' => 'new-access-token',
			'token_type' => 'Bearer',
			'expires_in' => 3600,
		];
		$this->userAuthService->method('refreshTokens')->willReturn($tokens);

		$this->responseService->method('createSuccessResponse')->willReturnCallback(
			fn ($data) => new JsonResponse($data)
		);

		$response = $this->controller->refresh($request);

		$this->assertInstanceOf(JsonResponse::class, $response);
		$this->assertEquals(200, $response->getStatusCode());
		$data = json_decode((string) $response->getBody(), TRUE);
		$this->assertEquals('new-access-token', $data['access_token']);
	}

	public function testRefreshFails(): void {
		$request = $this->createMock(ServerRequestInterface::class);
		$request->method('getParsedBody')->willReturn(['refresh_token' => 'invalid-token']);
		$request->method('getAttribute')->willReturnMap([
			['api.tenant', NULL, new TenantContext('test-tenant')],
			['api.id', NULL, 'public'],
			['api.version', NULL, '1'],
		]);

		$this->userAuthService->method('refreshTokens')->willThrowException(
			new RuntimeException('Invalid refresh token.', 401)
		);

		$this->responseService->method('createErrorResponse')->willReturnCallback(
			fn ($title, $detail, $status) => new JsonResponse(['title' => $title], $status)
		);

		$response = $this->controller->refresh($request);

		$this->assertEquals(401, $response->getStatusCode());
	}

	public function testLegacyLoginSuccessful(): void {
		$request = $this->createMock(ServerRequestInterface::class);
		$request->method('getParsedBody')->willReturn(['username' => 'testuser', 'password' => 'testpass']);
		$tenantContext = new TenantContext('test-tenant');
		$request->method('getAttribute')->willReturnCallback(function ($name) use ($tenantContext) {
			return match ($name) {
				'api.tenant' => $tenantContext,
				'api.id' => 'legacy',
				'api.version' => '1',
				'api.legacyMode' => new ApiLegacyMode(wrapData: FALSE),
				default => NULL
			};
		});

		$userRecord = ['uid' => 123, 'username' => 'testuser'];
		$this->userAuthService->method('authenticateUser')->willReturn($userRecord);

		$tokens = ['access_token' => 'access-token'];
		$this->userAuthService->method('generateTokensForUserWithScopeHandling')->willReturn($tokens);

		$this->responseService->method('createSuccessResponse')->willReturnCallback(
			function ($data) {
				return new JsonResponse($data, 200);
			}
		);

		$actualResponse = $this->controller->legacyLogin($request);

		$this->assertEquals(200, $actualResponse->getStatusCode());
		$data = json_decode((string) $actualResponse->getBody(), TRUE);
		$this->assertEquals('access-token', $data['bearerToken']);
	}

	public function testLegacyLoginFailsWithWrongApiId(): void {
		$request = $this->createMock(ServerRequestInterface::class);
		$request->method('getAttribute')->with('api.id')->willReturn('not-legacy');

		$this->responseService->method('createErrorResponse')->willReturnCallback(
			fn ($title, $detail, $status) => new JsonResponse(['title' => $title], $status)
		);

		$response = $this->controller->legacyLogin($request);
		$this->assertEquals(403, $response->getStatusCode());
	}

	public function testLogoutCallsUserAuthServiceWithBearerToken(): void {
		$request = $this->createMock(ServerRequestInterface::class);
		$request->method('getHeaderLine')->with('Authorization')->willReturn('Bearer test-token');

		$this->userAuthService->expects($this->once())
			->method('revokeUserToken')
			->with('test-token');

		$this->responseService->expects($this->once())
			->method('createSuccessResponse')
			->with(['message' => 'Logged out successfully'])
			->willReturn(new JsonResponse([]));

		$this->controller->logout($request);
	}

	public function testLogoutDoesNotCallUserAuthServiceWithoutBearerToken(): void {
		$request = $this->createMock(ServerRequestInterface::class);
		$request->method('getHeaderLine')->with('Authorization')->willReturn('Basic auth');

		$this->userAuthService->expects($this->never())
			->method('revokeUserToken');

		$this->responseService->expects($this->once())
			->method('createSuccessResponse')
			->willReturn(new JsonResponse([]));

		$this->controller->logout($request);
	}
}
