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
use TYPO3\CMS\Core\Http\Uri;

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
		$path = $request->getUri()->getPath();
		$apiPathPrefix = $this->extensionConfiguration->getApiPathPrefix();

		// If it's already a new API request, skip
		if (str_starts_with($path, $apiPathPrefix)) {
			return $handler->handle($request);
		}

		// Pattern: /{apiKey}/{entity}/{identifier}/{verb}
		// Example: /my-key/news/1/get
		$pathSegments = explode('/', trim($path, '/'));

		// We expect at least apiKey and entity (2 segments)
		// We filter out common TYPO3 entry points or typical folders to avoid false positives
		if (count($pathSegments) >= 2 && !$this->isReservedPath($pathSegments[0])) {
			$apiKey = $pathSegments[0];
			$entity = $pathSegments[1];
			$identifier = $pathSegments[2] ?? NULL;
			$verb = $pathSegments[3] ?? NULL;

			// Map to /api/{apiId}/v1/{entity}/{identifier}
			// We use the apiKey as apiId by default in legacy mode, or map it if needed.
			// Most sg_rest setups used the apiKey for authentication AND to identify the API.
			$newPath = rtrim($apiPathPrefix, '/') . '/' . $apiKey . '/v1/' . $entity;
			if ($identifier !== NULL) {
				$newPath .= '/' . $identifier;
			}
			// Note: sg_rest 'verb' is often redundant with HTTP methods or mapped to sub-paths.
			// If a verb exists, we might need special handling.
			if ($verb !== NULL) {
				$newPath .= '/' . $verb;
			}

			$request = $request->withUri(new Uri($newPath));
			// Tag the request as legacy
			$request = $request->withAttribute('api.isLegacy', TRUE);

			// Also ensure the bearertoken is passed if it's in the apiKey segment (common in some sg_rest versions)
			if (!$request->hasHeader('Authorization') && !$request->hasHeader('bearertoken')) {
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
		$reserved = ['typo3', 'fileadmin', 'typo3conf', 'typo3temp', 'uploads', 'api', 'docs'];
		return in_array(strtolower($segment), $reserved, TRUE);
	}
}
