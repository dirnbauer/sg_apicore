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

namespace SGalinski\SgApiCore\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SGalinski\SgApiCore\Configuration\ExtensionConfiguration;

/**
 * Middleware to handle legacy sg_rest URLs and map them to sg_apicore
 */
class LegacyRoutingMiddleware implements MiddlewareInterface {
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
	 * Processes the request and maps legacy URLs to the new API structure
	 *
	 * @param ServerRequestInterface $request
	 * @param RequestHandlerInterface $handler
	 * @return ResponseInterface
	 */
	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface {
		if (!$this->extensionConfiguration->isActivateLegacySupport()) {
			return $handler->handle($request);
		}

		$uri = $request->getUri();
		$path = $uri->getPath();
		$apiPathPrefix = $this->extensionConfiguration->getApiPathPrefix();

		// Respect TYPO3 Language Prefix
		/** @var \TYPO3\CMS\Core\Site\Entity\SiteLanguage $language */
		$language = $request->getAttribute('language');
		$languagePrefix = $language?->getBase()->getPath();
		$hasLanguagePrefix = FALSE;
		if ($languagePrefix !== NULL && $languagePrefix !== '/' && $languagePrefix !== '') {
			$languagePrefix = '/' . trim($languagePrefix, '/') . '/';
			if (str_starts_with($path, $languagePrefix)) {
				$path = '/' . ltrim(substr($path, strlen($languagePrefix)), '/');
				$hasLanguagePrefix = TRUE;
			}
		}

		// If it's already a new API request, skip - unless it contains legacy auth headers
		// which indicates that it might be a legacy request using the same path prefix
		$hasLegacyAuthHeader = $request->hasHeader('authtoken') || $request->hasHeader('bearertoken');
		if (str_starts_with($path, $apiPathPrefix) && !$hasLegacyAuthHeader) {
			return $handler->handle($request);
		}

		$queryParams = $request->getQueryParams();
		$isLegacyRequest = FALSE;
		$apiKey = '';
		$entity = '';
		$identifier = NULL;
		$verb = NULL;

		// 1. Check for query parameter based legacy requests
		// Example: /?type=1595576052&tx_sgrest[request]=authentication/authentication/getBearerToken
		if (isset($queryParams['tx_sgrest']['request']) || isset($queryParams['tx_sgrest_request'])) {
			$isLegacyRequest = TRUE;
			$legacyRequest = $queryParams['tx_sgrest']['request'] ?? $queryParams['tx_sgrest_request'];
			$requestSegments = explode('/', trim($legacyRequest, '/'));
			if (count($requestSegments) >= 2) {
				$apiKey = $requestSegments[0];
				/** @noinspection MultiAssignmentUsageInspection */
				$entity = $requestSegments[1];
				$identifier = $requestSegments[2] ?? NULL;
				// In query mode, usually the path contains more segments if needed
				if (count($requestSegments) > 3) {
					$verb = implode('/', array_slice($requestSegments, 3));
				}
			}
		}

		// 2. Check for path-based legacy requests (only if not already identified)
		// Pattern: /{apiKey}/{entity}/{identifier}/{verb}
		// Example: /my-key/news/1/get
		if (!$isLegacyRequest && $path !== '/' && $path !== '') {
			$pathSegments = explode('/', trim($path, '/'));

			// If it starts with the apiPathPrefix, we strip it to analyze the rest of the segments
			$strippedPathSegments = $pathSegments;
			$apiPathSegments = explode('/', trim($apiPathPrefix, '/'));
			$match = TRUE;
			foreach ($apiPathSegments as $index => $segment) {
				if (!isset($pathSegments[$index]) || strcasecmp($pathSegments[$index], $segment) !== 0) {
					$match = FALSE;
					break;
				}
			}

			if ($match) {
				$strippedPathSegments = array_slice($pathSegments, count($apiPathSegments));
			}

			// We only consider it a legacy API call if it has at least 2 segments,
			// doesn't look like a file (no dot in the last segment),
			// and doesn't start with a reserved TYPO3 path.
			// ALSO: We check if the type parameter is set to the typical sg_rest type
			// or if the request contains legacy auth headers.
			$isRestType = (isset($queryParams['type']) && (int) $queryParams['type'] === 1595576052);
			if (($isRestType || $hasLegacyAuthHeader) && count($strippedPathSegments) >= 2 && !str_contains(
				end($strippedPathSegments),
				'.'
			) && !$this->isReservedPath($strippedPathSegments[0])) {
				$isLegacyRequest = TRUE;
				$apiKey = $strippedPathSegments[0];
				/** @noinspection MultiAssignmentUsageInspection */
				$entity = $strippedPathSegments[1];
				$identifier = $strippedPathSegments[2] ?? NULL;
				$verb = $strippedPathSegments[3] ?? NULL;
			}
		}

		if ($isLegacyRequest && $apiKey !== '' && $entity !== '') {
			// Refined mapping strategy:
			// If it's a bearer token request, map to {apiPathPrefix}/legacy/v1/auth/login
			if ($apiKey === 'authentication' && $entity === 'authentication' && $identifier === 'getBearerToken') {
				$newPath = rtrim($apiPathPrefix, '/') . '/legacy/v1/auth/legacyLogin';
			} else {
				// Map other legacy requests to {apiPathPrefix}/legacy/v1/{apiKey}/{entity}/{identifier}
				$newPath = rtrim($apiPathPrefix, '/') . '/legacy/v1/' . $apiKey . '/' . $entity;
				if ($identifier !== NULL) {
					$newPath .= '/' . $identifier;
				}
				if ($verb !== NULL) {
					$newPath .= '/' . $verb;
				}
			}

			// Prepend a language prefix if it was present
			if ($hasLanguagePrefix) {
				$newPath = '/' . trim($languagePrefix, '/') . '/' . ltrim($newPath, '/');
			}

			$request = $request->withUri($uri->withPath($newPath));
			// Tag the request as legacy
			$request = $request->withAttribute('api.isLegacy', TRUE);
			// Also ensure the legacyApiKey is passed for authentication bridge
			if (!$request->hasHeader('Authorization')) {
				$request = $request->withAttribute('api.legacyApiKey', $apiKey);
			}
		}

		return $handler->handle($request);
	}

	/**
	 * Checks if the first segment is a reserved TYPO3 path
	 *
	 * @param string $segment
	 * @return bool
	 */
	protected function isReservedPath(string $segment): bool {
		$apiPathPrefix = trim($this->extensionConfiguration->getApiPathPrefix(), '/');
		$reserved = ['typo3', 'fileadmin', 'typo3conf', 'typo3temp', 'uploads', 'docs', $apiPathPrefix];
		return in_array(strtolower($segment), $reserved, TRUE);
	}
}
