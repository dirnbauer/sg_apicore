<?php

namespace SGalinski\SgApiCore\Tests\Unit\Security;

use Psr\Http\Message\ServerRequestInterface;
use SGalinski\SgApiCore\Domain\Repository\TokenRepository;
use SGalinski\SgApiCore\Security\AuthContext;
use SGalinski\SgApiCore\Security\BearerTokenProvider;
use SGalinski\SgApiCore\Service\JwtService;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Test case for BearerTokenProvider
 */
class BearerTokenProviderTest extends UnitTestCase {
	public function testAuthenticateReturnsNullIfNoBearerToken(): void {
		$request = $this->createStub(ServerRequestInterface::class);
		$request->method('getHeaderLine')->with('Authorization')->willReturn('');

		$tokenRepository = $this->createStub(TokenRepository::class);
		$jwtService = $this->createStub(JwtService::class);
		$provider = new BearerTokenProvider($tokenRepository, $jwtService);

		$result = $provider->authenticate($request, 'public', 'tenant-1');
		$this->assertNull($result);
	}

	public function testAuthenticateReturnsNullIfTokenNotFound(): void {
		$request = $this->createStub(ServerRequestInterface::class);
		$request->method('getHeaderLine')->with('Authorization')->willReturn('Bearer valid-token');

		$tokenRepository = $this->createStub(TokenRepository::class);
		$tokenRepository->method('findByApiAndTenant')->willReturn([]);

		$jwtService = $this->createStub(JwtService::class);
		$provider = new BearerTokenProvider($tokenRepository, $jwtService);

		$result = $provider->authenticate($request, 'public', 'tenant-1');
		$this->assertNull($result);
	}

	public function testAuthenticateReturnsAuthContextIfTokenValid(): void {
		$token = 'test-token';
		$request = $this->createStub(ServerRequestInterface::class);
		$request->method('getHeaderLine')->with('Authorization')->willReturn('Bearer ' . $token);

		$tokenRecord = [
			'uid' => 123,
			'token' => $token,
			'api_id' => 'public',
			'tenant_id' => 'tenant-1',
			'scopes' => json_encode(['read', 'write']),
			'expires_at' => 0
		];

		$tokenRepository = $this->createMock(TokenRepository::class);
		$tokenRepository->method('findByApiAndTenant')->with('public', 'tenant-1')->willReturn([$tokenRecord]);
		$tokenRepository->expects($this->once())->method('updateLastUsed')->with(123);

		$jwtService = $this->createStub(JwtService::class);
		$provider = new BearerTokenProvider($tokenRepository, $jwtService);

		$result = $provider->authenticate($request, 'public', 'tenant-1');

		$this->assertInstanceOf(AuthContext::class, $result);
		$this->assertEquals('public', $result->getApiId());
		$this->assertEquals('tenant-1', $result->getTenantId());
		$this->assertEquals(123, $result->getTokenUid());
		$this->assertEquals(['read', 'write'], $result->getScopes());
	}

	public function testAuthenticateReturnsNullIfTokenExpired(): void {
		$token = 'test-token';
		$request = $this->createStub(ServerRequestInterface::class);
		$request->method('getHeaderLine')->with('Authorization')->willReturn('Bearer ' . $token);

		$tokenRecord = [
			'uid' => 123,
			'token' => $token,
			'expires_at' => time() - 3600 // Expired 1 hour ago
		];

		$tokenRepository = $this->createStub(TokenRepository::class);
		$tokenRepository->method('findByApiAndTenant')->willReturn([$tokenRecord]);

		$jwtService = $this->createStub(JwtService::class);
		$provider = new BearerTokenProvider($tokenRepository, $jwtService);

		$result = $provider->authenticate($request, 'public', 'tenant-1');
		$this->assertNull($result);
	}
}
