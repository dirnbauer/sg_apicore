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
 * Registry for Resource configurations (Auto-CRUD)
 */
class ResourceRegistry implements SingletonInterface {
	/**
	 * @var array
	 */
	protected array $resources = [];

	/**
	 * Registers a resource for Auto-CRUD
	 *
	 * @param string $apiId The API ID this resource belongs to
	 * @param string $tableName The TYPO3 table name
	 * @param string $basePath The base path for this resource (e.g., /contents)
	 * @param array $config Additional configuration
	 * @return void
	 */
	public function registerResource(string $apiId, string $tableName, string $basePath, array $config = []): void {
		$this->resources[$apiId][$tableName] = array_merge([
			'table' => $tableName,
			'basePath' => $basePath,
			'idField' => 'uid',
			'allowedOperations' => ['list', 'get', 'create', 'update', 'delete'],
			'readFields' => [], // Empty = all (respecting TcaMapper defaults)
			'writeFields' => [],
			'fieldConfiguration' => [], // Map of table names to their field configurations (allowed/excluded)
			'tags' => [],
			'deleteMode' => 'soft',
			'rateLimit' => [],
			'requiredScopes' => [
				'list' => [],
				'get' => [],
				'create' => [],
				'update' => [],
				'delete' => []
			]
		], $config);
	}

	/**
	 * Returns all registered resources, optionally filtered by API ID
	 *
	 * @param string|null $apiId
	 * @return array
	 */
	public function getResources(?string $apiId = NULL): array {
		if ($apiId !== NULL) {
			return $this->resources[$apiId] ?? [];
		}

		return $this->resources;
	}

	/**
	 * Returns a specific resource configuration
	 *
	 * @param string $apiId
	 * @param string $tableName
	 * @return array|null
	 */
	public function getResource(string $apiId, string $tableName): ?array {
		return $this->resources[$apiId][$tableName] ?? NULL;
	}
}
