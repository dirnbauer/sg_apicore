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

namespace SGalinski\SgApiCore\EventListener;

use TYPO3\CMS\Backend\Backend\Event\ModifyClearCacheActionsEvent;
use TYPO3\CMS\Backend\Routing\Exception\RouteNotFoundException;
use TYPO3\CMS\Backend\Routing\UriBuilder;

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
	 */
	public function __construct(UriBuilder $uriBuilder) {
		$this->uriBuilder = $uriBuilder;
	}

	/**
	 * @param ModifyClearCacheActionsEvent $event
	 * @throws RouteNotFoundException
	 */
	public function __invoke(ModifyClearCacheActionsEvent $event): void {
		$event->addCacheAction([
			'id' => 'sg_apicore_clear_cache',
			'title' => 'LLL:EXT:sg_apicore/Resources/Private/Language/locallang_db.xlf:backend.cache_menu.title',
			'description' => 'LLL:EXT:sg_apicore/Resources/Private/Language/locallang_db.xlf:backend.cache_menu.description',
			'href' => (string) $this->uriBuilder->buildUriFromRoute('apicore_clear_cache'),
			'iconIdentifier' => 'actions-system-cache-clear',
		]);
	}
}
