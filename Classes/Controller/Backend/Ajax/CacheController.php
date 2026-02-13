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

namespace SGalinski\SgApiCore\Controller\Backend\Ajax;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SGalinski\SgApiCore\Service\CachePathService;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\NullResponse;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * AJAX controller for cache management
 */
class CacheController {
	/**
	 * @param CacheManager|null $cacheManager
	 * @param CachePathService|null $cachePathService
	 */
	public function __construct(
		protected ?CacheManager $cacheManager = NULL,
		protected ?CachePathService $cachePathService = NULL
	) {
		$this->cacheManager ??= GeneralUtility::makeInstance(CacheManager::class);
		$this->cachePathService ??= GeneralUtility::makeInstance(CachePathService::class);
	}

	/**
	 * Clears the API response cache
	 *
	 * @param ServerRequestInterface $request
	 * @return ResponseInterface
	 */
	public function clearCacheAction(ServerRequestInterface $request): ResponseInterface {
		try {
			$cache = $this->cacheManager->getCache('sg_apicore_responses');
			$cache->flush();

			// Also clear FastRoute cached routes to avoid stale routing definitions (centralized)
			$cacheDirectories = $this->cachePathService->getCacheDirectoriesToClear();
			foreach ($cacheDirectories as $cacheDirectory) {
				if (is_dir($cacheDirectory)) {
					GeneralUtility::rmdir($cacheDirectory, TRUE);
				}
			}

			return new NullResponse();
		} catch (\Exception $e) {
			return new JsonResponse(['success' => FALSE, 'error' => $e->getMessage()], 500);
		}
	}
}
