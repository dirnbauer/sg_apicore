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

namespace SGalinski\SgApiCore\Service;

use Psr\Http\Message\ResponseInterface;
use SGalinski\SgApiCore\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Service for creating standardized API responses
 */
class ResponseService implements SingletonInterface {
	/**
	 * @var ExtensionConfiguration
	 */
	protected ExtensionConfiguration $extensionConfiguration;

	/**
	 * @param ExtensionConfiguration $extensionConfiguration
	 */
	public function __construct(ExtensionConfiguration $extensionConfiguration) {
		$this->extensionConfiguration = $extensionConfiguration;
	}

	/**
	 * Creates a successful JSON response, optionally wrapped in a data envelope
	 *
	 * @param mixed $data The data to return
	 * @param array $meta Optional metadata
	 * @param int $status HTTP status code
	 * @param \SGalinski\SgApiCore\Attribute\ApiLegacyMode|null $legacyMode
	 * @param \SGalinski\SgApiCore\Attribute\ApiCache|null $apiCache
	 * @return ResponseInterface
	 */
	public function createSuccessResponse(
		mixed $data,
		array $meta = [],
		int $status = 200,
		?\SGalinski\SgApiCore\Attribute\ApiLegacyMode $legacyMode = NULL,
		?\SGalinski\SgApiCore\Attribute\ApiCache $apiCache = NULL
	): ResponseInterface {
		$wrapData = $this->extensionConfiguration->isResponseEnvelopeEnabled() || count($meta) > 0;
		if ($legacyMode !== NULL) {
			$wrapData = $legacyMode->wrapData;
		}

		if ($wrapData) {
			$response = [
				'data' => $data
			];
			if (count($meta) > 0) {
				$response['meta'] = $meta;
			}
		} else {
			$response = $data;
		}

		$headers = [
			'X-Content-Type-Options' => 'nosniff',
			'X-Frame-Options' => 'DENY',
		];

		if ($apiCache !== NULL && $apiCache->enabled && $apiCache->lifetime > 0) {
			$headers['Cache-Control'] = 'public, max-age=' . $apiCache->lifetime;
		} elseif ($apiCache !== NULL && !$apiCache->enabled) {
			$headers['Cache-Control'] = 'no-store, no-cache, must-revalidate';
		}

		return new JsonResponse($response, $status, $headers);
	}

	/**
	 * Creates a Problem JSON error response (RFC 7807) with localized title and detail
	 *
	 * @param string $title LLL key or raw string
	 * @param string $detail LLL key or raw string
	 * @param int $status
	 * @param string $type
	 * @param array $additionalData
	 * @param \SGalinski\SgApiCore\Attribute\ApiLegacyMode|null $legacyMode
	 * @return ResponseInterface
	 */
	public function createLocalizedErrorResponse(
		string $title,
		string $detail,
		int $status,
		string $type = 'about:blank',
		array $additionalData = [],
		?\SGalinski\SgApiCore\Attribute\ApiLegacyMode $legacyMode = NULL
	): ResponseInterface {
		/** @var LanguageService|null $languageService */
		$languageService = $GLOBALS['LANG'] ?? NULL;
		if ($languageService === NULL) {
			/** @var LanguageServiceFactory $languageServiceFactory */
			$languageServiceFactory = GeneralUtility::makeInstance(LanguageServiceFactory::class);
			$language = $GLOBALS['TYPO3_REQUEST']?->getAttribute('language');
			if ($language instanceof \TYPO3\CMS\Core\Site\Entity\SiteLanguage) {
				$languageService = $languageServiceFactory->createFromSiteLanguage($language);
			} else {
				$languageService = $languageServiceFactory->create('default');
			}
		}

		$translatedTitle = $languageService->sL($title);
		$translatedDetail = $languageService->sL($detail);

		return $this->createErrorResponse(
			$translatedTitle ?: $title,
			$translatedDetail ?: $detail,
			$status,
			$type,
			$additionalData,
			$legacyMode
		);
	}

	/**
	 * Creates a Problem JSON error response (RFC 7807)
	 *
	 * @param string $title
	 * @param string $detail
	 * @param int $status
	 * @param string $type
	 * @param array $additionalData
	 * @param \SGalinski\SgApiCore\Attribute\ApiLegacyMode|null $legacyMode
	 * @return ResponseInterface
	 */
	public function createErrorResponse(
		string $title,
		string $detail,
		int $status,
		string $type = 'about:blank',
		array $additionalData = [],
		?\SGalinski\SgApiCore\Attribute\ApiLegacyMode $legacyMode = NULL
	): ResponseInterface {
		$contentType = 'application/problem+json';
		$headers = [
			'Content-Type' => $contentType,
			'X-Content-Type-Options' => 'nosniff',
			'X-Frame-Options' => 'DENY',
		];
		if ($legacyMode?->legacyErrorFormat) {
			if ($legacyMode->source === 'sg_rest') {
				$response = [
					'message' => $detail
				];
				$contentType = 'application/json';
				$headers['Content-Type'] = $contentType;
			} else {
				$response = [
					'error' => $title,
					'message' => $detail,
					'code' => $status
				];
			}
		} else {
			$response = [
				'title' => $title,
				'detail' => $detail,
				'status' => $status,
				'type' => $type
			];
		}

		if (!isset($additionalData['requestId'])) {
			$requestId = $GLOBALS['TYPO3_REQUEST']?->getAttribute('api.requestId');
			if (is_string($requestId) && $requestId !== '') {
				$additionalData['requestId'] = $requestId;
			}
		}

		if (isset($additionalData['requestId']) && is_string($additionalData['requestId'])) {
			$headers['X-Request-ID'] = $additionalData['requestId'];
		}

		if (isset($additionalData['rateLimit']) && is_array($additionalData['rateLimit'])) {
			if (isset($additionalData['rateLimit']['limit'])) {
				$headers['X-RateLimit-Limit'] = (string) $additionalData['rateLimit']['limit'];
			}
			if (isset($additionalData['rateLimit']['remaining'])) {
				$headers['X-RateLimit-Remaining'] = (string) $additionalData['rateLimit']['remaining'];
			}
			if (isset($additionalData['rateLimit']['reset'])) {
				$headers['X-RateLimit-Reset'] = (string) $additionalData['rateLimit']['reset'];
			}
		}

		if (count($additionalData) > 0) {
			$response = array_merge($response, $additionalData);
		}

		return new JsonResponse($response, $status, $headers);
	}
}
