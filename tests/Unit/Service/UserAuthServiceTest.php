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
use Psr\EventDispatcher\EventDispatcherInterface;
use SGalinski\SgApiCore\Context\TenantContext;
use SGalinski\SgApiCore\Domain\Repository\TokenRepository;
use SGalinski\SgApiCore\Service\ApiRegistry;
use SGalinski\SgApiCore\Service\JwtService;
use SGalinski\SgApiCore\Service\TokenService;
use SGalinski\SgApiCore\Service\UserAuthService;
use TYPO3\CMS\Core\Crypto\PasswordHashing\PasswordHashFactory;
use TYPO3\CMS\Core\Crypto\PasswordHashing\PasswordHashInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Test case for UserAuthService
 */
#[\PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations]
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

	/**
	 * @var \SGalinski\SgApiCore\Configuration\ExtensionConfiguration|MockObject
	 */
	protected $extensionConfiguration;

	/**
	 * @var \SGalinski\SgApiCore\Service\LogService|MockObject
	 */
	protected $logService;

	/**
	 * @var JwtService|MockObject
	 */
	protected $jwtService;

	/**
	 * @var EventDispatcherInterface|MockObject
	 */
	protected $eventDispatcher;

	protected function setUp(): void {
		parent::setUp();
		$this->connectionPool = $this->createMock(ConnectionPool::class);
		$this->passwordHashFactory = $this->createMock(PasswordHashFactory::class);
		$this->apiRegistry = $this->createMock(ApiRegistry::class);
		$this->tokenService = $this->createMock(TokenService::class);
		$this->tokenRepository = $this->createMock(TokenRepository::class);
		$this->extensionConfiguration = $this->createMock(
			\SGalinski\SgApiCore\Configuration\ExtensionConfiguration::class
		);
		$this->logService = $this->createMock(\SGalinski\SgApiCore\Service\LogService::class);
		$this->jwtService = $this->createMock(JwtService::class);
		$this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);

		$this->service = new UserAuthService(
			$this->connectionPool,
			$this->passwordHashFactory,
			$this->apiRegistry,
			$this->tokenService,
			$this->tokenRepository,
			$this->extensionConfiguration,
			$this->logService,
			$this->eventDispatcher,
			$this->jwtService
		);
	}

	public function testAuthenticateUserSuccessful(): void {
		$username = 'testuser';
		$password = 'testpass';
		$hashedPassword = 'hashed-password';
		$userRecord = ['uid' => 123, 'username' => $username, 'password' => $hashedPassword];

		$tenantContext = new TenantContext('test-tenant');

		$queryBuilder = $this->createMock(QueryBuilder::class);
		$this->connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);
		$queryBuilder->method('select')->willReturn($queryBuilder);
		$queryBuilder->method('from')->willReturn($queryBuilder);
		$queryBuilder->method('where')->willReturn($queryBuilder);
		$queryBuilder->method('expr')->willReturn($this->createMock(ExpressionBuilder::class));

		$result = $this->createMock(\Doctrine\DBAL\Result::class);
		$queryBuilder->method('executeQuery')->willReturn($result);
		$result->method('fetchAssociative')->willReturn($userRecord);

		$hashInstance = $this->createMock(PasswordHashInterface::class);
		$this->passwordHashFactory->method('get')->willReturn($hashInstance);
		$hashInstance->method('checkPassword')->with($password, $hashedPassword)->willReturn(TRUE);

		$resultUser = $this->service->authenticateUser($username, $password, $tenantContext);
		$this->assertEquals($userRecord, $resultUser);
	}

	public function testFindUserByUsernameIgnoresDisabledOrDeleted(): void {
		$username = 'disableduser';
		$tenantContext = new TenantContext('test-tenant');

		$queryBuilder = $this->createMock(QueryBuilder::class);
		$this->connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);

		$exprBuilder = $this->createMock(ExpressionBuilder::class);
		$queryBuilder->method('expr')->willReturn($exprBuilder);

		// Expect filters for disable=0 and deleted=0
		$exprBuilder->expects($this->any())
			->method('eq')
			->willReturn('1=1');

		$queryBuilder->method('select')->willReturn($queryBuilder);
		$queryBuilder->method('from')->willReturn($queryBuilder);
		$queryBuilder->method('where')->willReturn($queryBuilder);
		$queryBuilder->method('andWhere')->willReturn($queryBuilder);

		$result = $this->createMock(\Doctrine\DBAL\Result::class);
		$queryBuilder->method('executeQuery')->willReturn($result);
		$result->method('fetchAssociative')->willReturn(FALSE);

		$resultUser = $this->service->findUserByUsername($username, $tenantContext);
		$this->assertNull($resultUser);
	}

	public function testResolveUserStoragePidsFromSiteConfig(): void {
		$site = $this->createMock(Site::class);
		$site->method('getConfiguration')->willReturn([
			'apicore' => ['userStoragePids' => '10,20'],
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
			'scopes' => '["user"]',
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
			'scopes' => '["user"]',
		];
		$this->tokenRepository->method('findByHashApiAndTenant')->willReturn($tokenRecord);

		$thrown = FALSE;
		try {
			$this->service->refreshTokens('expired-refresh-token', 'public', '1', $tenantContext);
		} catch (\RuntimeException $e) {
			$thrown = TRUE;
			$this->assertSame('Refresh token expired.', $e->getMessage());
		}
		$this->assertTrue($thrown, 'Expected RuntimeException not thrown.');
	}

	public function testRevokeUserTokenCallsRepositoryIfUserTokenFound(): void {
		$token = 'test-token';
		$tokenHash = hash('sha256', $token);
		$tokenRecord = [
			'uid' => 999,
			'user_id' => 123,
			'is_refresh_token' => 0,
			'api_id' => 'public',
			'tenant_id' => 'test-tenant',
		];

		$this->tokenRepository->expects($this->once())
			->method('findByHashGlobally')
			->with($tokenHash)
			->willReturn($tokenRecord);

		$this->tokenRepository->expects($this->exactly(2))
			->method('revoke')
			->willReturnCallback(function ($uid) {
				static $count = 0;
				$expectedUids = [999, 1000];
				$this->assertEquals($expectedUids[$count], $uid);
				$count++;
			});

		$this->tokenRepository->expects($this->once())
			->method('findAllWithFilters')
			->willReturn([['uid' => 1000, 'user_id' => 123]]);

		$this->service->revokeUserToken($token);
	}

	public function testRevokeUserTokenDoesNothingIfNonUserTokenFound(): void {
		$token = 'm2m-token';
		$tokenHash = hash('sha256', $token);
		$tokenRecord = ['uid' => 999, 'user_id' => 0];

		$this->tokenRepository->expects($this->once())
			->method('findByHashGlobally')
			->with($tokenHash)
			->willReturn($tokenRecord);

		$this->tokenRepository->expects($this->never())
			->method('revoke');

		$this->service->revokeUserToken($token);
	}

	public function testRevokeUserTokenDoesNothingIfTokenNotFound(): void {
		$token = 'non-existent-token';
		$tokenHash = hash('sha256', $token);

		$this->tokenRepository->expects($this->once())
			->method('findByHashGlobally')
			->with($tokenHash)
			->willReturn(NULL);

		$this->tokenRepository->expects($this->never())
			->method('revoke');

		$this->service->revokeUserToken($token);
	}

	public function testRevokeUserTokenCallsRepositoryIfJwtTokenFound(): void {
		$token = 'header.payload.signature';
		$tokenHash = hash('sha256', $token);
		$jti = 'test-jti';

		// First try by hash
		$this->tokenRepository->method('findByHashGlobally')
			->willReturnMap([
				[$tokenHash, NULL],
				[$jti, ['uid' => 999, 'user_id' => 123, 'is_refresh_token' => 0]],
			]);

		$this->jwtService->expects($this->once())
			->method('decode')
			->with($token)
			->willReturn(['jti' => $jti]);

		$this->tokenRepository->expects($this->once())
			->method('revoke')
			->with(999);

		$this->service->revokeUserToken($token);
	}
}
