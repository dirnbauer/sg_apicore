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
	 * @param string $apiId Unique API ID this schema belongs to
	 * @param string $schemaName Unique name for the schema
	 * @param array $schema The OpenAPI schema definition
	 * @param string $tableName Optional TCA table name for documentation enrichment
	 */
	public function registerSchema(string $apiId, string $schemaName, array $schema, string $tableName = ''): void {
		$this->schemas[$apiId][$schemaName] = $schema;
		if ($tableName !== '') {
			$this->schemaToTableMapping[$apiId][$schemaName] = $tableName;
		}
	}

	/**
	 * Maps a schema name to a TCA table name
	 *
	 * @param string $apiId
	 * @param string $schemaName
	 * @param string $tableName
	 */
	public function mapSchemaToTable(string $apiId, string $schemaName, string $tableName): void {
		$this->schemaToTableMapping[$apiId][$schemaName] = $tableName;
	}

	/**
	 * Returns the mapped table name for a schema name
	 *
	 * @param string $apiId
	 * @param string $schemaName
	 * @return string
	 */
	public function getTableNameForSchema(string $apiId, string $schemaName): string {
		return $this->schemaToTableMapping[$apiId][$schemaName] ?? '';
	}

	/**
	 * Returns all registered schemas, optionally filtered by API ID
	 *
	 * @param string|null $apiId
	 * @return array
	 */
	public function getSchemas(?string $apiId = NULL): array {
		if ($apiId !== NULL) {
			return $this->schemas[$apiId] ?? [];
		}

		return $this->schemas;
	}

	/**
	 * Returns a specific schema
	 *
	 * @param string $apiId
	 * @param string $schemaName
	 * @return array|null
	 */
	public function getSchema(string $apiId, string $schemaName): ?array {
		return $this->schemas[$apiId][$schemaName] ?? NULL;
	}

	/**
	 * Checks if a schema is registered
	 *
	 * @param string $apiId
	 * @param string $schemaName
	 * @return bool
	 */
	public function hasSchema(string $apiId, string $schemaName): bool {
		return isset($this->schemas[$apiId][$schemaName]);
	}
}
