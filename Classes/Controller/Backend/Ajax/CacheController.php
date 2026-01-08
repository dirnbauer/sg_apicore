<?php

namespace SGalinski\SgApiCore\Controller\Backend\Ajax;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\NullResponse;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * AJAX controller for cache management
 */
class CacheController {
	/**
	 * Clears the API response cache
	 *
	 * @param ServerRequestInterface $request
	 * @return ResponseInterface
	 */
	public function clearCacheAction(ServerRequestInterface $request): ResponseInterface {
		$cacheManager = GeneralUtility::makeInstance(CacheManager::class);
		try {
			$cache = $cacheManager->getCache('sg_apicore_responses');
			$cache->flush();
			return GeneralUtility::makeInstance(NullResponse::class);
		} catch (\Exception $e) {
			return new JsonResponse(['success' => FALSE, 'error' => $e->getMessage()], 500);
		}
	}
}
