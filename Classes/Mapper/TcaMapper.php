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

use TYPO3\CMS\Core\SingletonInterface;

/**
 * Service for mapping TYPO3 records to API responses based on TCA
 */
class TcaMapper implements SingletonInterface {
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
				if (($config['renderType'] ?? '') === 'inputDateTime' || (isset($config['dbType']) && ($config['dbType'] === 'datetime' || $config['dbType'] === 'date'))) {
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
}
