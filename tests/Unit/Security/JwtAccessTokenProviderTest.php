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

use PHPUnit\Framework\MockObject\MockObject;
use Psr\Http\Message\ServerRequestInterface;
use SGalinski\SgApiCore\Configuration\ExtensionConfiguration;
use SGalinski\SgApiCore\Domain\Repository\TokenRepository;
use SGalinski\SgApiCore\Security\JwtAccessTokenProvider;
use SGalinski\SgApiCore\Service\JwtService;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Test case for JwtAccessTokenProvider
 */
class JwtAccessTokenProviderTest extends UnitTestCase {
	/**
	 * @var JwtAccessTokenProvider
	 */
	protected $provider;

	/**
	 * @var JwtService|MockObject
	 */
	protected $jwtService;

	/**
	 * @var TokenRepository|MockObject
	 */
	protected $tokenRepository;

	/**
	 * @var ExtensionConfiguration|MockObject
	 */
	protected $extensionConfiguration;

	protected function setUp(): void {
		parent::setUp();
		$this->jwtService = $this->createMock(JwtService::class);
		$this->tokenRepository = $this->createMock(TokenRepository::class);
		$this->extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
		$this->provider = new JwtAccessTokenProvider(
			$this->jwtService,
			$this->tokenRepository,
			$this->extensionConfiguration
		);
	}

	public function testAuthenticateReturnsNullIfTokenInvalid(): void {
		$request = $this->createMock(ServerRequestInterface::class);
		$request->method('getHeaderLine')->with('Authorization')->willReturn('Bearer invalid-jwt');

		$this->jwtService->method('decode')->willReturn(NULL);

		$result = $this->provider->authenticate($request, 'test-api', 'tenant-1');
		$this->assertNull($result);
	}

	public function testAuthenticateReturnsContextIfTokenValidAndNotRevoked(): void {
		$request = $this->createMock(ServerRequestInterface::class);
		$request->method('getHeaderLine')->with('Authorization')->willReturn('Bearer valid.jwt.token');

		$payload = [
			'jti' => 'test-jti',
			'userId' => 123,
			'tenantId' => 'tenant-1',
			'apiId' => 'test-api',
			'scopes' => ['user'],
		];
		$this->jwtService->method('decode')->willReturn($payload);

		// findByHashGlobally returns the record if NOT revoked
		$this->tokenRepository->method('findByHashGlobally')->with('test-jti')->willReturn(['uid' => 1]);

		$result = $this->provider->authenticate($request, 'test-api', 'tenant-1');
		$this->assertNotNull($result);
		$this->assertEquals(123, $result->getUserId());
		$this->assertEquals('test-jti', $result->getTokenUid());
	}
}
