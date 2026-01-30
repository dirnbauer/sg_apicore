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

namespace SGalinski\SgApiCore\Mapper;

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Extbase\Persistence\Generic\PersistenceManager;

/**
 * Service for mapping TYPO3 records to API responses based on TCA and vice versa
 */
class TcaMapper implements SingletonInterface {
	/**
	 * @var PersistenceManager
	 */
	protected PersistenceManager $persistenceManager;

	/**
	 * @var ConnectionPool
	 */
	protected ConnectionPool $connectionPool;

	/**
	 * @param PersistenceManager $persistenceManager
	 * @param ConnectionPool $connectionPool
	 */
	public function __construct(PersistenceManager $persistenceManager, ConnectionPool $connectionPool) {
		$this->persistenceManager = $persistenceManager;
		$this->connectionPool = $connectionPool;
	}

	/**
	 * Converts an Extbase object to raw database data.
	 *
	 * @param object $object
	 * @param string $tableName
	 * @return array
	 */
	public function getRawData(object $object, string $tableName): array {
		$uid = (int) $this->persistenceManager->getIdentifierByObject($object);
		if ($uid <= 0) {
			return [];
		}

		$queryBuilder = $this->connectionPool->getQueryBuilderForTable($tableName);
		return (array) $queryBuilder->select('*')
			->from($tableName)
			->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid)))
			->executeQuery()
			->fetchAssociative();
	}

	/**
	 * Maps a single record to an array based on the allowed fields
	 *
	 * @param string $tableName
	 * @param array $record
	 * @param array $allowedFields If empty, all fields except excluded ones are returned
	 * @param array $excludedFields
	 * @return array
	 */
	public function mapRecord(
		string $tableName,
		array $record,
		array $allowedFields = [],
		array $excludedFields = ['tstamp', 'crdate', 'cruser_id', 'hidden', 'deleted', 't3ver_oid', 't3ver_id', 't3ver_wsid', 't3ver_label', 't3ver_state', 't3ver_stage', 't3ver_count', 't3ver_tstamp', 't3ver_move_id', 't3_origuid', 'l10n_parent', 'l10n_diffsource', 'l10n_state']
	): array {
		$mappedRecord = [];
		$tca = $GLOBALS['TCA'][$tableName] ?? [];
		if (empty($tca)) {
			return $record;
		}

		$fieldsToMap = $allowedFields;
		if (empty($fieldsToMap)) {
			$fieldsToMap = array_keys($tca['columns'] ?? []);
			// Always include uid
			if (!in_array('uid', $fieldsToMap, TRUE)) {
				$fieldsToMap[] = 'uid';
			}
			if (!in_array('pid', $fieldsToMap, TRUE)) {
				$fieldsToMap[] = 'pid';
			}
		}

		foreach ($fieldsToMap as $fieldName) {
			if (in_array($fieldName, $excludedFields, TRUE)) {
				continue;
			}

			if (!isset($record[$fieldName])) {
				continue;
			}

			$value = $record[$fieldName];
			$columnConfig = $tca['columns'][$fieldName]['config'] ?? [];

			$mappedRecord[$fieldName] = $this->transformValue($value, $columnConfig);
		}

		return $mappedRecord;
	}

	/**
	 * Maps multiple records
	 *
	 * @param string $tableName
	 * @param array $records
	 * @param array $allowedFields
	 * @param array $excludedFields
	 * @return array
	 */
	public function mapRecords(
		string $tableName,
		array $records,
		array $allowedFields = [],
		array $excludedFields = ['tstamp', 'crdate', 'cruser_id', 'hidden', 'deleted', 't3ver_oid', 't3ver_id', 't3ver_wsid', 't3ver_label', 't3ver_state', 't3ver_stage', 't3ver_count', 't3ver_tstamp', 't3ver_move_id', 't3_origuid', 'l10n_parent', 'l10n_diffsource', 'l10n_state']
	): array {
		$mappedRecords = [];
		foreach ($records as $record) {
			$mappedRecords[] = $this->mapRecord($tableName, $record, $allowedFields, $excludedFields);
		}
		return $mappedRecords;
	}

	/**
	 * Maps an array of data (e.g., from JSON) to database-ready values based on TCA
	 *
	 * @param string $tableName
	 * @param array $data
	 * @param array $allowedFields Whitelist of fields to accept
	 * @return array
	 */
	public function mapDataForDatabase(string $tableName, array $data, array $allowedFields = []): array {
		$mappedData = [];
		$tca = $GLOBALS['TCA'][$tableName] ?? [];
		if (empty($tca)) {
			return $data;
		}

		foreach ($data as $fieldName => $value) {
			if (!empty($allowedFields) && !in_array($fieldName, $allowedFields, TRUE)) {
				continue;
			}

			if (!isset($tca['columns'][$fieldName])) {
				continue;
			}

			$columnConfig = $tca['columns'][$fieldName]['config'] ?? [];
			$mappedData[$fieldName] = $this->transformValueForDatabase($value, $columnConfig);
		}

		return $mappedData;
	}

	/**
	 * Transforms a value based on TCA configuration
	 *
	 * @param mixed $value
	 * @param array $config
	 * @return mixed
	 */
	protected function transformValue(mixed $value, array $config): mixed {
		$type = $config['type'] ?? '';

		switch ($type) {
			case 'check':
				return (bool) $value;
			case 'input':
				if (isset($config['eval']) && str_contains($config['eval'], 'int')) {
					return (int) $value;
				}
				if (($config['renderType'] ?? '') === 'inputDateTime' ||
					(isset($config['dbType']) && ($config['dbType'] === 'datetime' || $config['dbType'] === 'date'))
				) {
					if (is_numeric($value)) {
						return date(\DateTimeInterface::ATOM, (int) $value);
					}
					return $value;
				}
				return $value;
			case 'number':
				return str_contains($config['format'] ?? '', 'decimal') ? (float) $value : (int) $value;
			case 'select':
			case 'group':
				if (isset($config['maxitems']) && (int) $config['maxitems'] > 1) {
					return is_string($value) ? explode(',', $value) : $value;
				}
				return $value;
			default:
				return $value;
		}
	}

	/**
	 * Transforms a value to be database-ready based on TCA configuration
	 *
	 * @param mixed $value
	 * @param array $config
	 * @return mixed
	 */
	protected function transformValueForDatabase(mixed $value, array $config): mixed {
		$type = $config['type'] ?? '';

		switch ($type) {
			case 'check':
				return $value ? 1 : 0;
			case 'input':
				if (isset($config['eval']) && str_contains($config['eval'], 'int')) {
					return (int) $value;
				}
				if (($config['renderType'] ?? '') === 'inputDateTime' || (isset($config['dbType']) &&
						($config['dbType'] === 'datetime' || $config['dbType'] === 'date'))
				) {
					if (is_string($value)) {
						$timestamp = strtotime($value);
						return $timestamp !== FALSE ? $timestamp : (int) $value;
					}
					return (int) $value;
				}
				return (string) $value;
			case 'number':
				return str_contains($config['format'] ?? '', 'decimal') ? (float) $value : (int) $value;
			case 'select':
			case 'group':
				if (is_array($value)) {
					return implode(',', $value);
				}
				return (string) $value;
			default:
				return $value;
		}
	}
}
