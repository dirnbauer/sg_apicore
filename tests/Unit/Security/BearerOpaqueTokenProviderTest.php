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

namespace SGalinski\SgApiCore\Tests\Unit\Security;

use Psr\Http\Message\ServerRequestInterface;
use SGalinski\SgApiCore\Configuration\ExtensionConfiguration;
use SGalinski\SgApiCore\Domain\Repository\TokenRepository;
use SGalinski\SgApiCore\Security\AuthContext;
use SGalinski\SgApiCore\Security\BearerOpaqueTokenProvider;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Test case for BearerOpaqueTokenProvider
 */
class BearerOpaqueTokenProviderTest extends UnitTestCase {
	public function testAuthenticateReturnsNullIfNoBearerToken(): void {
		$request = $this->createStub(ServerRequestInterface::class);
		$request->method('getHeaderLine')->with('Authorization')->willReturn('');

		$tokenRepository = $this->createStub(TokenRepository::class);
		$provider = new BearerOpaqueTokenProvider($tokenRepository, $this->createExtensionConfiguration());

		$result = $provider->authenticate($request, 'public', 'tenant-1');
		$this->assertNull($result);
	}

	public function testAuthenticateReturnsNullIfTokenNotFound(): void {
		$request = $this->createStub(ServerRequestInterface::class);
		$request->method('getHeaderLine')->with('Authorization')->willReturn('Bearer valid-token');

		$tokenRepository = $this->createStub(TokenRepository::class);
		$tokenRepository->method('findByHashApiAndTenant')->willReturn(NULL);

		$provider = new BearerOpaqueTokenProvider($tokenRepository, $this->createExtensionConfiguration());

		$result = $provider->authenticate($request, 'public', 'tenant-1');
		$this->assertNull($result);
	}

	public function testAuthenticateReturnsAuthContextIfTokenValid(): void {
		$token = 'test-token';
		$tokenHash = hash('sha256', $token);
		$request = $this->createStub(ServerRequestInterface::class);
		$request->method('getHeaderLine')->with('Authorization')->willReturn('Bearer ' . $token);

		$tokenRecord = [
			'uid' => 123,
			'token_hash' => $tokenHash,
			'api_id' => 'public',
			'tenant_id' => 'tenant-1',
			'scopes' => json_encode(['read', 'write']),
			'expires_at' => 0
		];

		$tokenRepository = $this->createMock(TokenRepository::class);
		$tokenRepository->method('findByHashApiAndTenant')->with($tokenHash, 'public', 'tenant-1')->willReturn(
			$tokenRecord
		);
		$tokenRepository->expects($this->once())->method('updateLastUsed')->with(123);

		$provider = new BearerOpaqueTokenProvider($tokenRepository, $this->createExtensionConfiguration());

		$result = $provider->authenticate($request, 'public', 'tenant-1');

		$this->assertInstanceOf(AuthContext::class, $result);
		$this->assertEquals('public', $result->getApiId());
		$this->assertEquals('tenant-1', $result->getTenantId());
		$this->assertEquals(123, $result->getTokenUid());
		$this->assertEquals(['read', 'write'], $result->getScopes());
	}

	public function testAuthenticateReturnsNullIfTokenExpired(): void {
		$token = 'test-token';
		$tokenHash = hash('sha256', $token);
		$request = $this->createStub(ServerRequestInterface::class);
		$request->method('getHeaderLine')->with('Authorization')->willReturn('Bearer ' . $token);

		$tokenRecord = [
			'uid' => 123,
			'token_hash' => $tokenHash,
			'expires_at' => time() - 3600 // Expired 1 hour ago
		];

		$tokenRepository = $this->createStub(TokenRepository::class);
		$tokenRepository->method('findByHashApiAndTenant')->willReturn($tokenRecord);

		$provider = new BearerOpaqueTokenProvider($tokenRepository, $this->createExtensionConfiguration());

		$result = $provider->authenticate($request, 'public', 'tenant-1');
		$this->assertNull($result);
	}

	public function testAuthenticateRespectsSiteRootPageIdFromTenantContext(): void {
		$token = 'test-token';
		$tokenHash = hash('sha256', $token);
		$request = $this->createStub(ServerRequestInterface::class);
		$request->method('getHeaderLine')->with('Authorization')->willReturn('Bearer ' . $token);

		$tenantContext = new \SGalinski\SgApiCore\Context\TenantContext(tenantId: 'tenant-1', siteRootPageId: 456);
		$request->method('getAttribute')->with('api.tenant')->willReturn($tenantContext);

		$tokenRecord = [
			'uid' => 123,
			'token_hash' => $tokenHash,
			'api_id' => 'public',
			'tenant_id' => 'tenant-1',
			'scopes' => '[]',
			'expires_at' => 0
		];

		$tokenRepository = $this->createMock(TokenRepository::class);
		$tokenRepository->expects($this->once())
			->method('findByHashApiAndTenant')
			->with($tokenHash, 'public', 'tenant-1', 456)
			->willReturn($tokenRecord);

		$provider = new BearerOpaqueTokenProvider($tokenRepository, $this->createExtensionConfiguration());

		$result = $provider->authenticate($request, 'public', 'tenant-1');
		$this->assertInstanceOf(AuthContext::class, $result);
	}
	protected function createExtensionConfiguration(): ExtensionConfiguration {
		$extensionConfiguration = $this->createStub(ExtensionConfiguration::class);
		$extensionConfiguration->method('isActivateLegacySupport')->willReturn(FALSE);
		return $extensionConfiguration;
	}
}
