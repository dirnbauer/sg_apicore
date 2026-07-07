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
use SGalinski\SgApiCore\Security\BackendBearerOpaqueTokenProvider;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

class BackendBearerOpaqueTokenProviderTest extends UnitTestCase {
	protected bool $resetSingletonInstances = TRUE;

	protected TokenRepository&MockObject $tokenRepository;
	protected BackendBearerOpaqueTokenProvider $provider;

	protected function setUp(): void {
		parent::setUp();
		$this->tokenRepository = $this->createMock(TokenRepository::class);
		$extensionConfiguration = $this->createStub(ExtensionConfiguration::class);
		$languageServiceFactory = $this->createStub(LanguageServiceFactory::class);
		$languageServiceFactory->method('create')->willReturn($this->createStub(LanguageService::class));
		$this->provider = new BackendBearerOpaqueTokenProvider(
			$this->tokenRepository,
			$extensionConfiguration,
			$languageServiceFactory
		);
	}

	protected function tearDown(): void {
		unset($GLOBALS['BE_USER'], $GLOBALS['LANG']);
		parent::tearDown();
	}

	/**
	 * @param string $token
	 * @param array<string, string> $headers
	 * @param array<string, mixed> $queryParams
	 * @return ServerRequestInterface&MockObject
	 */
	protected function createRequest(string $token = 'secret-token', array $headers = [], array $queryParams = []): ServerRequestInterface&MockObject {
		$request = $this->createMock(ServerRequestInterface::class);
		$request->method('getHeaderLine')->willReturnCallback(
			static function (string $name) use ($token, $headers): string {
				if ($name === 'Authorization') {
					return $token === '' ? '' : 'Bearer ' . $token;
				}
				return $headers[$name] ?? '';
			}
		);
		$request->method('getQueryParams')->willReturn($queryParams);
		$request->method('getAttribute')->willReturn(NULL);
		return $request;
	}

	public function testReturnsNullWithoutToken(): void {
		$this->tokenRepository->expects($this->never())->method('findByHashApiAndTenant');
		$this->assertNull($this->provider->authenticate($this->createRequest(''), 'abilities', NULL));
	}

	public function testReturnsNullWhenTokenIsUnknown(): void {
		$this->tokenRepository->method('findByHashApiAndTenant')->willReturn(NULL);
		$this->assertNull($this->provider->authenticate($this->createRequest(), 'abilities', NULL));
	}

	public function testReturnsNullForExpiredToken(): void {
		$this->tokenRepository->method('findByHashApiAndTenant')->willReturn([
			'uid' => 5,
			'expires_at' => time() - 60,
			'be_user_uid' => 3,
		]);
		$this->assertNull($this->provider->authenticate($this->createRequest(), 'abilities', NULL));
	}

	public function testReturnsNullWithoutBackendUserBinding(): void {
		$this->tokenRepository->method('findByHashApiAndTenant')->willReturn([
			'uid' => 5,
			'expires_at' => 0,
			'be_user_uid' => 0,
			'scopes' => '["news:read"]',
		]);
		$this->assertNull($this->provider->authenticate($this->createRequest(), 'abilities', NULL));
		$this->assertArrayNotHasKey('BE_USER', $GLOBALS);
	}

	public function testReturnsNullWhenBackendUserRecordIsMissing(): void {
		$this->tokenRepository->method('findByHashApiAndTenant')->willReturn([
			'uid' => 5,
			'expires_at' => 0,
			'be_user_uid' => 3,
		]);

		$backendUser = $this->createMock(BackendUserAuthentication::class);
		$backendUser->user = NULL;
		$backendUser->expects($this->once())->method('setBeUserByUid')->with(3);
		GeneralUtility::addInstance(BackendUserAuthentication::class, $backendUser);

		$this->assertNull($this->provider->authenticate($this->createRequest(), 'abilities', NULL));
	}

	public function testBootsBackendUserAndReturnsAuthContextWithScopes(): void {
		$this->tokenRepository->method('findByHashApiAndTenant')->willReturn([
			'uid' => 5,
			'expires_at' => 0,
			'be_user_uid' => 3,
			'scopes' => '["abilities:read", "news:write", 42]',
		]);
		$this->tokenRepository->expects($this->once())->method('updateLastUsed')->with(5);

		$backendUser = $this->createMock(BackendUserAuthentication::class);
		$backendUser->user = ['uid' => 3, 'username' => 'api'];
		$backendUser->expects($this->once())->method('fetchGroupData');
		$backendUser->expects($this->never())->method('setTemporaryWorkspace');
		GeneralUtility::addInstance(BackendUserAuthentication::class, $backendUser);

		$authContext = $this->provider->authenticate($this->createRequest(), 'abilities', NULL);

		$this->assertNotNull($authContext);
		$this->assertSame(['abilities:read', 'news:write'], $authContext->getScopes());
		$this->assertSame(3, $authContext->getUserId());
		$this->assertSame($backendUser, $GLOBALS['BE_USER']);
	}

	public function testAppliesWorkspaceFromHeader(): void {
		$this->tokenRepository->method('findByHashApiAndTenant')->willReturn([
			'uid' => 5,
			'expires_at' => 0,
			'be_user_uid' => 3,
			'scopes' => '',
		]);

		$backendUser = $this->createMock(BackendUserAuthentication::class);
		$backendUser->user = ['uid' => 3];
		$backendUser->expects($this->once())->method('setTemporaryWorkspace')->with(7);
		GeneralUtility::addInstance(BackendUserAuthentication::class, $backendUser);

		$request = $this->createRequest(headers: ['X-TYPO3-Workspace' => '7']);
		$this->assertNotNull($this->provider->authenticate($request, 'abilities', NULL));
	}
}
