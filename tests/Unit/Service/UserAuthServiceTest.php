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

namespace SGalinski\SgApiCore\Tests\Unit\Service;

use PHPUnit\Framework\MockObject\MockObject;
use SGalinski\SgApiCore\Context\TenantContext;
use SGalinski\SgApiCore\Service\ApiRegistry;
use SGalinski\SgApiCore\Service\TokenService;
use SGalinski\SgApiCore\Service\UserAuthService;
use SGalinski\SgApiCore\Domain\Repository\TokenRepository;
use TYPO3\CMS\Core\Crypto\PasswordHashing\PasswordHashFactory;
use TYPO3\CMS\Core\Crypto\PasswordHashing\PasswordHashInterface;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Test case for UserAuthService
 */
class UserAuthServiceTest extends UnitTestCase {
	/**
	 * @var UserAuthService
	 */
	protected UserAuthService $service;

	/**
	 * @var ConnectionPool|MockObject
	 */
	protected $connectionPool;

	/**
	 * @var PasswordHashFactory|MockObject
	 */
	protected $passwordHashFactory;

	/**
	 * @var ApiRegistry|MockObject
	 */
	protected $apiRegistry;

	/**
	 * @var TokenService|MockObject
	 */
	protected $tokenService;

	/**
	 * @var TokenRepository|MockObject
	 */
	protected $tokenRepository;

	protected function setUp(): void {
		parent::setUp();
		$this->connectionPool = $this->createStub(ConnectionPool::class);
		$this->passwordHashFactory = $this->createStub(PasswordHashFactory::class);
		$this->apiRegistry = $this->createStub(ApiRegistry::class);
		$this->tokenService = $this->createStub(TokenService::class);
		$this->tokenRepository = $this->createStub(TokenRepository::class);

		$this->service = new UserAuthService(
			$this->connectionPool,
			$this->passwordHashFactory,
			$this->apiRegistry,
			$this->tokenService,
			$this->tokenRepository
		);
	}

	public function testAuthenticateUserSuccessful(): void {
		$username = 'testuser';
		$password = 'testpass';
		$hashedPassword = 'hashed-password';
		$userRecord = ['uid' => 123, 'username' => $username, 'password' => $hashedPassword];

		$tenantContext = new TenantContext('test-tenant');

		$queryBuilder = $this->createStub(QueryBuilder::class);
		$this->connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);
		$queryBuilder->method('select')->willReturn($queryBuilder);
		$queryBuilder->method('from')->willReturn($queryBuilder);
		$queryBuilder->method('where')->willReturn($queryBuilder);
		$queryBuilder->method('expr')->willReturn($this->createStub(ExpressionBuilder::class));

		$result = $this->createStub(\Doctrine\DBAL\Result::class);
		$queryBuilder->method('executeQuery')->willReturn($result);
		$result->method('fetchAssociative')->willReturn($userRecord);

		$hashInstance = $this->createStub(PasswordHashInterface::class);
		$this->passwordHashFactory->method('get')->willReturn($hashInstance);
		$hashInstance->method('checkPassword')->with($password, $hashedPassword)->willReturn(TRUE);

		$resultUser = $this->service->authenticateUser($username, $password, $tenantContext);
		$this->assertEquals($userRecord, $resultUser);
	}

	public function testResolveUserStoragePidsFromSiteConfig(): void {
		$site = $this->createStub(Site::class);
		$site->method('getConfiguration')->willReturn([
			'apicore' => ['userStoragePids' => '10,20']
		]);
		$tenantContext = new TenantContext('test-tenant', 'test-site', 1, $site);

		$pids = $this->service->resolveUserStoragePids($tenantContext);
		$this->assertEquals([10, 20], $pids);
	}

	public function testGenerateTokensForUser(): void {
		$user = ['uid' => 123, 'username' => 'testuser'];
		$tenantContext = new TenantContext('test-tenant', 'test-site', 1);
		$this->apiRegistry->method('getSecurityConfig')->willReturn(['authProviders' => []]);
		$this->tokenService->method('generateRandomToken')->willReturn('random-token');

		$tokens = $this->service->generateTokensForUser($user, 'public', '1', $tenantContext, ['user']);

		$this->assertEquals('random-token', $tokens['access_token']);
		$this->assertEquals('random-token', $tokens['refresh_token']);
	}

	public function testRefreshTokensSuccessful(): void {
		$tenantContext = new TenantContext('test-tenant', 'test-site', 1);
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

		$tokens = $this->service->refreshTokens('valid-refresh-token', 'public', '1', $tenantContext);

		$this->assertEquals('new-access-token', $tokens['access_token']);
		$this->assertEquals('Bearer', $tokens['token_type']);
	}

	public function testRefreshTokensExpired(): void {
		$tenantContext = new TenantContext('test-tenant', 'test-site', 1);
		$tokenRecord = [
			'uid' => 456,
			'user_id' => 123,
			'is_refresh_token' => 1,
			'expires_at' => time() - 3600,
			'scopes' => '["user"]'
		];
		$this->tokenRepository->method('findByHashApiAndTenant')->willReturn($tokenRecord);

		$thrown = false;
		try {
			$this->service->refreshTokens('expired-refresh-token', 'public', '1', $tenantContext);
		} catch (\RuntimeException $e) {
			$thrown = true;
			$this->assertSame('Refresh token expired.', $e->getMessage());
		}
		$this->assertTrue($thrown, 'Expected RuntimeException not thrown.');
	}
}
