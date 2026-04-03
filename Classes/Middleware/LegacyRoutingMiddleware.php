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
		$language = $request->getAttribute('language');
		$languagePrefix = NULL;
		if ($language instanceof \TYPO3\CMS\Core\Site\Entity\SiteLanguage) {
			$languagePrefix = $language->getBase()->getPath();
		}
		$hasLanguagePrefix = FALSE;
		if ($languagePrefix !== NULL && $languagePrefix !== '/' && $languagePrefix !== '') {
			$languagePrefix = '/' . trim($languagePrefix, '/') . '/';
			if (str_starts_with($path, $languagePrefix)) {
				$path = '/' . ltrim(substr($path, \strlen($languagePrefix)), '/');
				$hasLanguagePrefix = TRUE;
			}
		}

		// If it's already a new API request, skip - unless it contains legacy auth headers
		// which indicates that it might be a legacy request using the same path prefix
		$hasLegacyAuthHeader = $request->hasHeader('authtoken') || $request->hasHeader('bearertoken');
		if (!$hasLegacyAuthHeader && str_starts_with($path, $apiPathPrefix)) {
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
			if (\count($requestSegments) >= 2) {
				$apiKey = $requestSegments[0];
				/** @noinspection MultiAssignmentUsageInspection */
				$entity = $requestSegments[1];
				$identifier = $requestSegments[2] ?? NULL;
				// In query mode, usually the path contains more segments if needed
				if (\count($requestSegments) > 3) {
					$verb = implode('/', \array_slice($requestSegments, 3));
				}

				// Tag the request as legacy
				$request = $request->withAttribute('api.isLegacy', TRUE);
				$request = $request->withAttribute('api.id', 'legacy');
				$request = $request->withAttribute('api.version', '1');

				// Map other legacy requests to /{apiKey}/{entity}/{identifier}[/{verb}]
				$remainingPathAttribute = '/' . $apiKey . '/' . $entity;
				if ($identifier !== NULL) {
					$remainingPathAttribute .= '/' . $identifier;
				}

				if ($verb !== NULL) {
					$remainingPathAttribute .= '/' . $verb;
				}
				$request = $request->withAttribute('api.remainingPath', $remainingPathAttribute);
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
				$strippedPathSegments = \array_slice($pathSegments, \count($apiPathSegments));

				// If it already starts with 'legacy/v1', we skip these segments for analysis, but we still want to
				// process the rest as a legacy request if it has additional path parameters
				if (\count($strippedPathSegments) >= 2 && $strippedPathSegments[0] === 'legacy' &&
					$strippedPathSegments[1] === 'v1'
				) {
					$strippedPathSegments = \array_slice($strippedPathSegments, 2);
				}
			}

			// We only consider it a legacy API call if it has at least 2 segments,
			// doesn't look like a file (no dot in the last segment),
			// and doesn't start with a reserved TYPO3 path.
			// ALSO: We check if the type parameter is set to the typical sg_rest type
			// or if the request contains legacy auth headers.
			$isRestType = (isset($queryParams['type']) && (int) $queryParams['type'] === 1595576052);
			if (($isRestType || $hasLegacyAuthHeader) && \count($strippedPathSegments) >= 2 &&
				!str_contains(end($strippedPathSegments), '.') && !$this->isReservedPath($strippedPathSegments[0])
			) {
				$isLegacyRequest = TRUE;
				$apiKey = $strippedPathSegments[0];
				/** @noinspection MultiAssignmentUsageInspection */
				$entity = $strippedPathSegments[1];
				$identifier = $strippedPathSegments[2] ?? NULL;
				$verb = $strippedPathSegments[3] ?? NULL;

				// Special case: If the verb looks like a key (e.g. "page", "limit"), it might be part of the
				// key-value pairs instead of being a verb. However, sg_rest usually has a verb as the 4th segment
				//(index 3).
				// But in the example: /api/legacy/v1/offer/offer/list/page/c1
				// apiKey: offer, entity: offer, identifier: list, verb: page
				// Wait, if it's /offer/offer/list, then the identifier is 'list'.
				// If there is more, then 'page' would be the verb. But 'page' is a key for a query param.

				// Extract additional key/value pairs from the path if they exist
				// Example: /api/offer/offer/list/page/1/limit/20
				$remainingSegments = \array_slice($strippedPathSegments, 4);
				if ($verb !== NULL && \count($remainingSegments) % 2 !== 0) {
					// This means we have an odd number of segments after the verb (which is at index 3).
					// This usually happens if the verb is actually a key.
					$possibleVerbAsKey = $verb;
					$possibleValue = $remainingSegments[0] ?? NULL;
					if ($possibleValue !== NULL) {
						$queryParams[$possibleVerbAsKey] = $possibleValue;
						$remainingSegments = \array_slice($remainingSegments, 1);
						$verb = NULL;
					}
				}

				if (\count($remainingSegments) > 0) {
					for ($i = 0, $iMax = \count($remainingSegments); $i < $iMax; $i += 2) {
						if (isset($remainingSegments[$i + 1])) {
							$queryParams[$remainingSegments[$i]] = $remainingSegments[$i + 1];
						}
					}
				}

				// Move attribute setting here to ensure they are available for following middlewares
				// Tag the request as legacy
				$request = $request->withAttribute('api.isLegacy', TRUE);
				$request = $request->withAttribute('api.id', 'legacy');
				$request = $request->withAttribute('api.version', '1');

				// Map legacy requests to /{apiKey}/{entity}/{identifier}[/{verb}]
				$remainingPath = '/' . $apiKey . '/' . $entity;
				if ($identifier !== NULL) {
					$remainingPath .= '/' . $identifier;
				}

				// We DO NOT append the verb if it was identified as a query parameter key (and thus set to NULL)
				if ($verb !== NULL) {
					$remainingPath .= '/' . $verb;
				}

				$request = $request->withAttribute('api.remainingPath', $remainingPath);
			}
		}

		if ($isLegacyRequest && $apiKey !== '' && $entity !== '') {
			$request = $request->withQueryParams($queryParams);
			$remainingPath = $request->getAttribute('api.remainingPath');

			// Refined mapping strategy:
			// If it's a bearer token request, map to {apiPathPrefix}/legacy/v1/auth/login
			if ($apiKey === 'authentication' && $entity === 'authentication' && $identifier === 'getBearerToken') {
				$remainingPath = '/auth/legacyLogin';
				$request = $request->withAttribute('api.remainingPath', $remainingPath);
			}

			$newPath = rtrim($apiPathPrefix, '/') . '/legacy/v1' . $remainingPath;

			// Prepend a language prefix if it was present
			if ($hasLanguagePrefix) {
				$newPath = '/' . trim($languagePrefix, '/') . '/' . ltrim($newPath, '/');
			}

			$request = $request->withUri($uri->withPath($newPath));
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
		return \in_array(strtolower($segment), $reserved, TRUE);
	}
}
