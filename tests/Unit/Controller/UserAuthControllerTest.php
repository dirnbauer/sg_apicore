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

use Doctrine\DBAL\Result;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Http\Message\ServerRequestInterface;
use SGalinski\SgApiCore\Context\TenantContext;
use SGalinski\SgApiCore\Controller\UserAuthController;
use SGalinski\SgApiCore\Domain\Repository\TokenRepository;
use SGalinski\SgApiCore\Service\ApiRegistry;
use SGalinski\SgApiCore\Service\ResponseService;
use SGalinski\SgApiCore\Service\TokenService;
use TYPO3\CMS\Core\Crypto\PasswordHashing\PasswordHashFactory;
use TYPO3\CMS\Core\Crypto\PasswordHashing\PasswordHashInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Test case for UserAuthController
 */
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
	 * @var PasswordHashFactory|MockObject
	 */
	protected $passwordHashFactory;

	/**
	 * @var ConnectionPool|MockObject
	 */
	protected $connectionPool;

	/**
	 * @var ResponseService|MockObject
	 */
	protected $responseService;

	protected function setUp(): void {
		parent::setUp();
		$this->tokenRepository = $this->createStub(TokenRepository::class);
		$this->tokenService = $this->createStub(TokenService::class);
		$this->apiRegistry = $this->createStub(ApiRegistry::class);
		$this->passwordHashFactory = $this->createStub(PasswordHashFactory::class);
		$this->connectionPool = $this->createStub(ConnectionPool::class);
		$this->responseService = $this->createStub(ResponseService::class);

		$this->controller = new UserAuthController(
			$this->tokenRepository,
			$this->tokenService,
			$this->apiRegistry,
			$this->passwordHashFactory,
			$this->connectionPool,
			$this->responseService
		);
	}

	public function testLoginSuccessful(): void {
		$tokenServiceMock = $this->createMock(TokenService::class);
		$this->controller = new UserAuthController(
			$this->tokenRepository,
			$tokenServiceMock,
			$this->apiRegistry,
			$this->passwordHashFactory,
			$this->connectionPool,
			$this->responseService
		);
		$request = $this->createStub(ServerRequestInterface::class);
		$request->method('getParsedBody')->willReturn(['username' => 'testuser', 'password' => 'testpass']);
		$tenantContext = new TenantContext('test-tenant');
		$request->method('getAttribute')->willReturnMap([
			['api.tenant', NULL, $tenantContext],
			['api.id', NULL, 'public'],
			['api.version', NULL, '1']
		]);

		$queryBuilder = $this->createStub(QueryBuilder::class);
		$this->connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);
		$queryBuilder->method('select')->willReturn($queryBuilder);
		$queryBuilder->method('from')->willReturn($queryBuilder);
		$queryBuilder->method('where')->willReturn($queryBuilder);
		$queryBuilder->method('expr')->willReturn($this->createStub(ExpressionBuilder::class));

		$result = $this->createStub(Result::class);
		$queryBuilder->method('executeQuery')->willReturn($result);
		$result->method('fetchAssociative')->willReturn([
			'uid' => 123,
			'username' => 'testuser',
			'password' => 'hashed-password'
		]);

		$hashInstance = $this->createStub(PasswordHashInterface::class);
		$this->passwordHashFactory->method('get')->willReturn($hashInstance);
		$hashInstance->method('checkPassword')->willReturn(TRUE);

		$this->apiRegistry->method('getSecurityConfig')->willReturn(['authProviders' => []]);

		$tokenServiceMock->method('generateRandomToken')->willReturn('random-token');
		$tokenServiceMock->expects($this->exactly(2))->method('createToken');

		$this->responseService->method('createSuccessResponse')->willReturnCallback(
			fn ($data) => new JsonResponse($data)
		);

		$response = $this->controller->login($request);

		$this->assertInstanceOf(JsonResponse::class, $response);
		$this->assertEquals(200, $response->getStatusCode());
		$data = json_decode((string) $response->getBody(), TRUE);
		$this->assertEquals('random-token', $data['access_token']);
		$this->assertEquals('random-token', $data['refresh_token']);
	}

	public function testLoginInvalidCredentials(): void {
		$request = $this->createStub(ServerRequestInterface::class);
		$request->method('getParsedBody')->willReturn(['username' => 'testuser', 'password' => 'wrongpass']);

		$queryBuilder = $this->createStub(QueryBuilder::class);
		$this->connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);
		$queryBuilder->method('select')->willReturn($queryBuilder);
		$queryBuilder->method('from')->willReturn($queryBuilder);
		$queryBuilder->method('where')->willReturn($queryBuilder);
		$queryBuilder->method('expr')->willReturn($this->createStub(ExpressionBuilder::class));

		$result = $this->createStub(Result::class);
		$queryBuilder->method('executeQuery')->willReturn($result);
		$result->method('fetchAssociative')->willReturn([
			'uid' => 123,
			'username' => 'testuser',
			'password' => 'hashed-password'
		]);

		$hashInstance = $this->createStub(PasswordHashInterface::class);
		$this->passwordHashFactory->method('get')->willReturn($hashInstance);
		$hashInstance->method('checkPassword')->willReturn(FALSE);

		$this->responseService->method('createErrorResponse')->willReturnCallback(
			fn ($title, $detail, $status) => new JsonResponse(['title' => $title], $status)
		);

		$response = $this->controller->login($request);

		$this->assertEquals(401, $response->getStatusCode());
	}

	public function testRefreshSuccessful(): void {
		$request = $this->createStub(ServerRequestInterface::class);
		$request->method('getParsedBody')->willReturn(['refresh_token' => 'valid-refresh-token']);
		$request->method('getAttribute')->willReturnMap([
			['api.tenant', NULL, new TenantContext('test-tenant')],
			['api.id', NULL, 'public'],
			['api.version', NULL, '1']
		]);

		$tokenRecord = [
			'uid' => 456,
			'user_id' => 123,
			'is_refresh_token' => 1,
			'expires_at' => 0,
			'scopes' => '["user"]'
		];
		$this->tokenRepository->method('findByHashApiAndTenant')->willReturn($tokenRecord);

		$this->apiRegistry->method('getSecurityConfig')->willReturn(['authProviders' => []]);
		$this->tokenService->method('generateRandomToken')->willReturn('new-access-token');

		$this->responseService->method('createSuccessResponse')->willReturnCallback(
			fn ($data) => new JsonResponse($data)
		);

		$response = $this->controller->refresh($request);

		$this->assertInstanceOf(JsonResponse::class, $response);
		$this->assertEquals(200, $response->getStatusCode());
		$data = json_decode((string) $response->getBody(), TRUE);
		$this->assertEquals('new-access-token', $data['access_token']);
	}

	public function testLegacyLoginSuccessful(): void {
		$request = $this->createStub(ServerRequestInterface::class);
		$request->method('getParsedBody')->willReturn(['username' => 'testuser', 'password' => 'testpass']);
		$tenantContext = new TenantContext('test-tenant');
		$request->method('getAttribute')->willReturnMap([
			['api.tenant', NULL, $tenantContext],
			['api.id', NULL, 'legacy'],
			['api.version', NULL, '1']
		]);

		$queryBuilder = $this->createStub(QueryBuilder::class);
		$this->connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);
		$queryBuilder->method('select')->willReturn($queryBuilder);
		$queryBuilder->method('from')->willReturn($queryBuilder);
		$queryBuilder->method('where')->willReturn($queryBuilder);
		$queryBuilder->method('expr')->willReturn($this->createStub(ExpressionBuilder::class));

		$result = $this->createStub(Result::class);
		$queryBuilder->method('executeQuery')->willReturn($result);
		$result->method('fetchAssociative')->willReturn([
			'uid' => 123,
			'username' => 'testuser',
			'password' => 'hashed-password'
		]);

		$hashInstance = $this->createStub(PasswordHashInterface::class);
		$this->passwordHashFactory->method('get')->willReturn($hashInstance);
		$hashInstance->method('checkPassword')->willReturn(TRUE);

		$this->apiRegistry->method('getSecurityConfig')->willReturn(['authProviders' => []]);

		$this->tokenService->method('generateRandomToken')->willReturn('random-token');

		$this->responseService->method('createSuccessResponse')->willReturnCallback(
			function ($data, $meta, $status, $legacyMode) {
				if ($legacyMode && $legacyMode->wrapData === FALSE && isset($data['bearerToken'])) {
					return new JsonResponse($data, $status);
				}
				return new JsonResponse($data, $status);
			}
		);

		$response = $this->controller->legacyLogin($request);

		$this->assertInstanceOf(JsonResponse::class, $response);
		$this->assertEquals(200, $response->getStatusCode());
		$data = json_decode((string) $response->getBody(), TRUE);
		$this->assertEquals('random-token', $data['bearerToken']);
		$this->assertArrayNotHasKey('access_token', $data);
	}

	public function testLegacyLoginWithUserAndPassParameters(): void {
		$request = $this->createStub(ServerRequestInterface::class);
		$request->method('getParsedBody')->willReturn(['user' => 'testuser', 'pass' => 'testpass']);
		$tenantContext = new TenantContext('test-tenant');
		$request->method('getAttribute')->willReturnMap([
			['api.tenant', NULL, $tenantContext],
			['api.id', NULL, 'legacy'],
			['api.version', NULL, '1']
		]);

		$queryBuilder = $this->createStub(QueryBuilder::class);
		$this->connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);
		$queryBuilder->method('select')->willReturn($queryBuilder);
		$queryBuilder->method('from')->willReturn($queryBuilder);
		$queryBuilder->method('where')->willReturn($queryBuilder);
		$queryBuilder->method('expr')->willReturn($this->createStub(ExpressionBuilder::class));

		$result = $this->createStub(Result::class);
		$queryBuilder->method('executeQuery')->willReturn($result);
		$result->method('fetchAssociative')->willReturn([
			'uid' => 123,
			'username' => 'testuser',
			'password' => 'hashed-password'
		]);

		$hashInstance = $this->createStub(PasswordHashInterface::class);
		$this->passwordHashFactory->method('get')->willReturn($hashInstance);
		$hashInstance->method('checkPassword')->willReturn(TRUE);

		$this->apiRegistry->method('getSecurityConfig')->willReturn(['authProviders' => []]);
		$this->tokenService->method('generateRandomToken')->willReturn('random-token');
		$this->responseService->method('createSuccessResponse')->willReturnCallback(
			fn ($data) => new JsonResponse($data)
		);

		$response = $this->controller->legacyLogin($request);
		$this->assertEquals(200, $response->getStatusCode());
	}

	public function testLegacyLoginFailsWithAuthenticatedFrontendUserSession(): void {
		$request = $this->createStub(ServerRequestInterface::class);
		$feUser = $this->createStub(\TYPO3\CMS\Frontend\Authentication\FrontendUserAuthentication::class);
		$feUser->user = ['uid' => 123, 'username' => 'testuser'];

		$tenantContext = new TenantContext('test-tenant');
		$request->method('getAttribute')->willReturnMap([
			['api.tenant', NULL, $tenantContext],
			['api.id', NULL, 'legacy'],
			['api.version', NULL, '1'],
			['frontend.user', NULL, $feUser]
		]);

		$this->responseService->method('createErrorResponse')->willReturnCallback(
			fn ($title, $detail, $status) => new JsonResponse(['title' => $title], $status)
		);

		$response = $this->controller->legacyLogin($request);
		$this->assertEquals(400, $response->getStatusCode());
	}
}
