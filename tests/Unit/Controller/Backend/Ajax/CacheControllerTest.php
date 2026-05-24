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

namespace SGalinski\SgApiCore\Tests\Unit\Controller\Backend\Ajax;

use Psr\Http\Message\ServerRequestInterface;
use SGalinski\SgApiCore\Controller\Backend\Ajax\CacheController;
use SGalinski\SgApiCore\Service\CachePathService;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\UserAspect;
use TYPO3\CMS\Core\Http\NullResponse;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Test case for CacheController
 */
class CacheControllerTest extends UnitTestCase {
	public function testClearCacheActionReturnsForbiddenForNonAdminUsers(): void {
		$cacheManager = $this->createMock(CacheManager::class);
		$cacheManager->expects($this->never())->method('getCache');
		$cachePathService = $this->createStub(CachePathService::class);

		$controller = new CacheController($cacheManager, $cachePathService, $this->createBackendUserContext(FALSE));
		$response = $controller->clearCacheAction($this->createStub(ServerRequestInterface::class));

		$this->assertSame(403, $response->getStatusCode());
		$this->assertJsonStringEqualsJsonString(
			'{"success": false, "error": "Access denied."}',
			(string) $response->getBody()
		);
	}

	public function testClearCacheActionFlushesCachesForAdminUsers(): void {
		$cache = $this->createMock(FrontendInterface::class);
		$cache->expects($this->once())->method('flush');

		$cacheManager = $this->createMock(CacheManager::class);
		$cacheManager->expects($this->once())
			->method('getCache')
			->with('sg_apicore_responses')
			->willReturn($cache);

		$cachePathService = $this->createStub(CachePathService::class);
		$cachePathService->method('getCacheDirectoriesToClear')->willReturn([]);

		$controller = new CacheController($cacheManager, $cachePathService, $this->createBackendUserContext(TRUE));
		$response = $controller->clearCacheAction($this->createStub(ServerRequestInterface::class));

		$this->assertInstanceOf(NullResponse::class, $response);
	}

	/**
	 * Creates a context with a backend user admin flag.
	 *
	 * @param bool $isAdmin
	 * @return Context
	 */
	protected function createBackendUserContext(bool $isAdmin): Context {
		$backendUser = new BackendUserAuthentication();
		$backendUser->user = [
			$backendUser->userid_column => 1,
			$backendUser->username_column => 'test-user',
			'admin' => $isAdmin ? 1 : 0,
		];

		$context = new Context();
		$context->setAspect('backend.user', new UserAspect($backendUser));

		return $context;
	}
}
