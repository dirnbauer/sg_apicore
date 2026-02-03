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
 * Registry for OpenAPI schemas
 */
class SchemaRegistry implements SingletonInterface {
	/**
	 * @var array
	 */
	protected array $schemas = [];

	/**
	 * @var array
	 */
	protected array $schemaToTableMapping = [];

	/**
	 * Registers a schema
	 *
	 * @param string $schemaName Unique name for the schema
	 * @param array $schema The OpenAPI schema definition
	 * @param string $tableName Optional TCA table name for documentation enrichment
	 */
	public function registerSchema(string $schemaName, array $schema, string $tableName = ''): void {
		$this->schemas[$schemaName] = $schema;
		if ($tableName !== '') {
			$this->schemaToTableMapping[$schemaName] = $tableName;
		}
	}

	/**
	 * Maps a schema name to a TCA table name
	 *
	 * @param string $schemaName
	 * @param string $tableName
	 */
	public function mapSchemaToTable(string $schemaName, string $tableName): void {
		$this->schemaToTableMapping[$schemaName] = $tableName;
	}

	/**
	 * Returns the mapped table name for a schema name
	 *
	 * @param string $schemaName
	 * @return string
	 */
	public function getTableNameForSchema(string $schemaName): string {
		return $this->schemaToTableMapping[$schemaName] ?? '';
	}

	/**
	 * Returns all registered schemas
	 *
	 * @return array
	 */
	public function getSchemas(): array {
		return $this->schemas;
	}

	/**
	 * Returns a specific schema
	 *
	 * @param string $schemaName
	 * @return array|null
	 */
	public function getSchema(string $schemaName): ?array {
		return $this->schemas[$schemaName] ?? NULL;
	}

	/**
	 * Checks if a schema is registered
	 *
	 * @param string $schemaName
	 * @return bool
	 */
	public function hasSchema(string $schemaName): bool {
		return isset($this->schemas[$schemaName]);
	}
}
