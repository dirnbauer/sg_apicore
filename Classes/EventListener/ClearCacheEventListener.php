<?php

namespace SGalinski\SgApiCore\EventListener;

use TYPO3\CMS\Backend\Backend\Event\ModifyClearCacheActionsEvent;
use TYPO3\CMS\Backend\Routing\Exception\RouteNotFoundException;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Event listener to add the API cache clear action to the TYPO3 cache menu
 */
class ClearCacheEventListener {
	/**
	 * @param ModifyClearCacheActionsEvent $event
	 * @throws RouteNotFoundException
	 */
	public function __invoke(ModifyClearCacheActionsEvent $event): void {
		$uriBuilder = GeneralUtility::makeInstance(UriBuilder::class);
		$event->addCacheAction([
			'id' => 'sg_apicore_clear_cache',
			'title' => 'LLL:EXT:sg_apicore/Resources/Private/Language/locallang_db.xlf:backend.cache_menu.title',
			'description' => 'LLL:EXT:sg_apicore/Resources/Private/Language/locallang_db.xlf:backend.cache_menu.description',
			'href' => (string) $uriBuilder->buildUriFromRoute('apicore_clear_cache'),
			'iconIdentifier' => 'actions-system-cache-clear',
		]);
	}
}
