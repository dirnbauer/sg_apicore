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

use TYPO3\CMS\Core\SingletonInterface;

/**
 * Registry for API configurations
 */
class ApiRegistry implements SingletonInterface {
	/**
	 * @var array
	 */
	protected array $apis = [];

	/**
	 * Registers an API
	 *
	 * @param string $apiId Unique identifier for the API
	 * @param array $versions Supported versions (e.g., ['1', '2'])
	 * @param string|null $basePath Optional base path override
	 */
	public function registerApi(string $apiId, array $versions, ?string $basePath = NULL): void {
		$this->apis[$apiId] = [
			'versions' => $versions,
			'basePath' => $basePath
		];
	}

	/**
	 * Returns the configuration for the given API ID
	 *
	 * @param string $apiId
	 * @return array|null
	 */
	public function getApi(string $apiId): ?array {
		return $this->apis[$apiId] ?? NULL;
	}

	/**
	 * Returns all registered APIs
	 *
	 * @return array
	 */
	public function getApis(): array {
		return $this->apis;
	}

	/**
	 * Checks if an API is registered
	 *
	 * @param string $apiId
	 * @return bool
	 */
	public function hasApi(string $apiId): bool {
		return isset($this->apis[$apiId]);
	}
}
