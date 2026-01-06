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

namespace SGalinski\SgApiCore\Tests\Unit\Security;

use Psr\Http\Message\ServerRequestInterface;
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
		$provider = new BearerOpaqueTokenProvider($tokenRepository);

		$result = $provider->authenticate($request, 'public', 'tenant-1');
		$this->assertNull($result);
	}

	public function testAuthenticateReturnsNullIfTokenNotFound(): void {
		$request = $this->createStub(ServerRequestInterface::class);
		$request->method('getHeaderLine')->with('Authorization')->willReturn('Bearer valid-token');

		$tokenRepository = $this->createStub(TokenRepository::class);
		$tokenRepository->method('findByHashApiAndTenant')->willReturn(NULL);

		$provider = new BearerOpaqueTokenProvider($tokenRepository);

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

		$provider = new BearerOpaqueTokenProvider($tokenRepository);

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

		$provider = new BearerOpaqueTokenProvider($tokenRepository);

		$result = $provider->authenticate($request, 'public', 'tenant-1');
		$this->assertNull($result);
	}
}
