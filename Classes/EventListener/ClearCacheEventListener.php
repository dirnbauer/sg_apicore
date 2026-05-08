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

namespace SGalinski\SgApiCore\EventListener;

use TYPO3\CMS\Backend\Backend\Event\ModifyClearCacheActionsEvent;
use TYPO3\CMS\Backend\Routing\Exception\RouteNotFoundException;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Context\Context;

/**
 * Event listener to add the API cache clear action to the TYPO3 cache menu
 */
class ClearCacheEventListener {
	/**
	 * @var UriBuilder
	 */
	protected UriBuilder $uriBuilder;

	/**
	 * @param UriBuilder $uriBuilder
	 * @param Context $context
	 */
	public function __construct(UriBuilder $uriBuilder, protected Context $context) {
		$this->uriBuilder = $uriBuilder;
	}

	/**
	 * @param ModifyClearCacheActionsEvent $event
	 * @throws RouteNotFoundException
	 */
	public function __invoke(ModifyClearCacheActionsEvent $event): void {
		if (!$this->isBackendAdmin()) {
			return;
		}

		$event->addCacheAction([
			'id' => 'sg_apicore_clear_cache',
			'title' => 'LLL:EXT:sg_apicore/Resources/Private/Language/locallang_db.xlf:backend.cache_menu.title',
			'description' => 'LLL:EXT:sg_apicore/Resources/Private/Language/locallang_db.xlf:backend.cache_menu.description',
			'href' => (string) $this->uriBuilder->buildUriFromRoute('apicore_clear_cache'),
			'iconIdentifier' => 'actions-system-cache-clear',
		]);
	}

	/**
	 * Checks whether the current backend user is an administrator.
	 *
	 * @return bool
	 */
	protected function isBackendAdmin(): bool {
		return (bool) $this->context->getAspect('backend.user')->get('isAdmin');
	}
}
