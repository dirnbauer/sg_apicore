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

use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ServerRequestInterface;
use SGalinski\SgApiCore\Security\AuthContext;
use SGalinski\SgApiCore\Security\BackendUserProvider;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\UserAspect;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

class BackendUserProviderTest extends UnitTestCase {
	protected bool $resetSingletonInstances = TRUE;

	#[Test]
	public function authenticateReturnsNullIfUserNotLoggedIn(): void {
		$context = $this->createMock(Context::class);
		$userAspect = $this->createMock(UserAspect::class);
		$userAspect->method('get')->with('isLoggedIn')->willReturn(FALSE);
		$context->method('getAspect')->with('backend.user')->willReturn($userAspect);
		GeneralUtility::setSingletonInstance(Context::class, $context);

		$provider = new BackendUserProvider();
		$request = $this->createMock(ServerRequestInterface::class);
		$result = $provider->authenticate($request, 'backend', 'tenant');

		$this->assertNull($result);
	}

	#[Test]
	public function authenticateReturnsAuthContextIfUserLoggedIn(): void {
		$context = $this->createMock(Context::class);
		$userAspect = $this->createMock(UserAspect::class);
		$userAspect->method('get')->willReturnMap([
			['isLoggedIn', TRUE],
			['id', 123]
		]);
		$context->method('getAspect')->with('backend.user')->willReturn($userAspect);
		GeneralUtility::setSingletonInstance(Context::class, $context);

		$provider = new BackendUserProvider();
		$request = $this->createMock(ServerRequestInterface::class);
		$result = $provider->authenticate($request, 'backend', 'tenant');

		$this->assertInstanceOf(AuthContext::class, $result);
		$this->assertEquals('backend', $result->getApiId());
		$this->assertEquals(123, $result->getUserId());
		$this->assertContains('backend', $result->getScopes());
	}
}
