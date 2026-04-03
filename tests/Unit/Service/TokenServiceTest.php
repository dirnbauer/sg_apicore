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

use SGalinski\SgApiCore\Configuration\ExtensionConfiguration;
use SGalinski\SgApiCore\Domain\Repository\TokenRepository;
use SGalinski\SgApiCore\Service\JwtService;
use SGalinski\SgApiCore\Service\TokenService;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Test case for TokenService
 */
class TokenServiceTest extends UnitTestCase {
	protected $tokenRepository;
	protected $jwtService;
	protected $extensionConfiguration;
	protected $connectionPool;
	protected $service;

	protected function setUp(): void {
		parent::setUp();
		$this->tokenRepository = $this->createMock(TokenRepository::class);
		$this->jwtService = $this->createMock(JwtService::class);
		$this->extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
		$this->connectionPool = $this->createMock(ConnectionPool::class);
		$this->service = new TokenService(
			$this->tokenRepository,
			$this->jwtService,
			$this->extensionConfiguration,
			$this->connectionPool
		);
	}

	public function testGenerateRandomTokenReturnsCorrectLength(): void {
		$token = $this->service->generateRandomToken();
		$this->assertEquals(64, \strlen($token)); // bin2hex(32 bytes)
	}

	public function testCreateTokenInsertsIntoDatabase(): void {
		$connection = $this->createMock(Connection::class);
		$this->connectionPool->method('getConnectionForTable')->with('tx_apicore_token')->willReturn($connection);

		$token = 'test-token';
		$tokenHash = hash('sha256', $token);

		$connection->expects($this->once())
			->method('insert')
			->with(
				'tx_apicore_token',
				$this->callback(function ($data) use ($tokenHash) {
					return $data['token_hash'] === $tokenHash &&
						$data['api_id'] === 'test-api' &&
						$data['tenant_id'] === 'tenant-1' &&
						$data['scopes'] === '["read"]';
				})
			);

		$connection->method('lastInsertId')->willReturn('123');

		$result = $this->service->createToken($token, 'test-api', 'tenant-1', 0, ['read']);

		$this->assertEquals(123, $result);
	}

	public function testCreateTokenWithJwtDoesNotHash(): void {
		$connection = $this->createMock(Connection::class);
		$this->connectionPool->method('getConnectionForTable')->with('tx_apicore_token')->willReturn($connection);

		$jti = 'test-jti';

		$connection->expects($this->once())
			->method('insert')
			->with(
				'tx_apicore_token',
				$this->callback(function ($data) use ($jti) {
					return $data['token_hash'] === $jti;
				})
			);

		$this->service->createToken($jti, 'test-api', 'tenant-1', 0, ['read'], NULL, FALSE, NULL, '', TRUE);
	}

	public function testGenerateJwtAccessTokenCallsJwtService(): void {
		$this->extensionConfiguration->method('getTokenExpirationTime')->willReturn(3600);
		$this->jwtService->expects($this->once())
			->method('encode')
			->with(
				$this->callback(function ($payload) {
					return $payload['userId'] === 1 &&
						$payload['apiId'] === 'test-api' &&
						$payload['tenantId'] === 'tenant-1' &&
						$payload['scopes'] === ['user'] &&
						isset($payload['exp']) &&
						$payload['jti'] === 'test-jti';
				})
			)
			->willReturn('mocked-jwt');

		$result = $this->service->generateJwtAccessToken(1, 'test-api', 'tenant-1', ['user'], 'test-jti');
		$this->assertEquals('mocked-jwt', $result);
	}
}
