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

namespace SGalinski\SgApiCore\Tests\Unit\EventListener;

use SGalinski\SgApiCore\EventListener\ClearCacheEventListener;
use TYPO3\CMS\Backend\Backend\Event\ModifyClearCacheActionsEvent;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\UserAspect;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Test case for ClearCacheEventListener
 */
class ClearCacheEventListenerTest extends UnitTestCase {
	public function testInvokeAddsCacheActionForAdminUsers(): void {
		$uriBuilder = $this->createMock(UriBuilder::class);
		$uriBuilder->expects($this->once())
			->method('buildUriFromRoute')
			->with('apicore_clear_cache')
			->willReturn(new Uri('/typo3/apicore/clear-cache'));

		$event = new ModifyClearCacheActionsEvent([], []);
		$listener = new ClearCacheEventListener($uriBuilder, $this->createBackendUserContext(TRUE));
		$listener($event);

		$cacheActions = $event->getCacheActions();
		$this->assertCount(1, $cacheActions);
		$this->assertSame('sg_apicore_clear_cache', $cacheActions[0]['id']);
		$this->assertSame('/typo3/apicore/clear-cache', $cacheActions[0]['href']);
	}

	public function testInvokeDoesNotAddCacheActionForNonAdminUsers(): void {
		$uriBuilder = $this->createMock(UriBuilder::class);
		$uriBuilder->expects($this->never())->method('buildUriFromRoute');

		$event = new ModifyClearCacheActionsEvent([], []);
		$listener = new ClearCacheEventListener($uriBuilder, $this->createBackendUserContext(FALSE));
		$listener($event);

		$this->assertSame([], $event->getCacheActions());
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
