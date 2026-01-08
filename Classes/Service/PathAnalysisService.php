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
