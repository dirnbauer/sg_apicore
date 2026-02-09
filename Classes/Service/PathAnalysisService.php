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

namespace SGalinski\SgApiCore\Service;

use SGalinski\SgApiCore\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\SingletonInterface;

/**
 * Service to analyze API paths and extract apiId, version, and remainingPath
 */
class PathAnalysisService implements SingletonInterface {
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
	 * Analyzes the given path and returns an array with apiId, version, and remainingPath if successful
	 *
	 * @param string $path
	 * @param string|null $prefixOverride
	 * @return array|null
	 */
	public function analyze(string $path, ?string $prefixOverride = NULL): ?array {
		if ($GLOBALS['TYPO3_REQUEST'] instanceof \Psr\Http\Message\ServerRequestInterface) {
			$request = $GLOBALS['TYPO3_REQUEST'];
			$apiId = $request->getAttribute('api.id');
			$version = $request->getAttribute('api.version');
			$remainingPath = $request->getAttribute('api.remainingPath');

			if ($apiId !== NULL && $version !== NULL && $remainingPath !== NULL) {
				return [
					'apiId' => $apiId,
					'version' => $version,
					'remainingPath' => $remainingPath
				];
			}
		}

		$apiPathPrefix = $prefixOverride ?? $this->extensionConfiguration->getApiPathPrefix();
		if (!str_contains($path, $apiPathPrefix)) {
			return NULL;
		}

		$relativeWeight = strpos($path, $apiPathPrefix) + strlen($apiPathPrefix);
		$relativeRequestPath = substr($path, $relativeWeight);
		$relativeRequestPath = ltrim($relativeRequestPath, '/');
		$relativeRequestPath = rtrim($relativeRequestPath, '/');

		// Regex to match {apiId}/v(\d+)(/.*)?
		if (preg_match('#^([^/]+)/v(\d+)(/.*)?$#', $relativeRequestPath, $matches)) {
			$remainingPath = $matches[3] ?? '/';
			// Normalize the remaining path
			$remainingPath = $remainingPath !== '/' ? rtrim($remainingPath, '/') : $remainingPath;
			if ($remainingPath === '') {
				$remainingPath = '/';
			}

			return [
				'apiId' => $matches[1],
				'version' => $matches[2],
				'remainingPath' => $remainingPath
			];
		}

		return NULL;
	}
}
