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
use TYPO3\CMS\Core\Resource\FileReference;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Persistence\Generic\PersistenceManager;
use TYPO3\CMS\Core\Resource\FileInterface;

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
	 * @var ResourceFactory
	 */
	protected ResourceFactory $resourceFactory;

	/**
	 * @param PersistenceManager $persistenceManager
	 * @param ConnectionPool $connectionPool
	 * @param ResourceFactory $resourceFactory
	 */
	public function __construct(
		PersistenceManager $persistenceManager,
		ConnectionPool $connectionPool,
		ResourceFactory $resourceFactory
	) {
		$this->persistenceManager = $persistenceManager;
		$this->connectionPool = $connectionPool;
		$this->resourceFactory = $resourceFactory;
	}

	/**
	 * Converts an Extbase object to raw database data.
	 *
	 * @param object $object
	 * @param string $tableName
	 * @return array
	 * @throws \Doctrine\DBAL\Exception
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
	 * @param int $resolveDepth Depth of relation resolution
	 * @return array
	 */
	public function mapRecord(
		string $tableName,
		array $record,
		array $allowedFields = [],
		array $excludedFields = ['tstamp', 'crdate', 'cruser_id', 'hidden', 'deleted', 't3ver_oid', 't3ver_id', 't3ver_wsid', 't3ver_label', 't3ver_state', 't3ver_stage', 't3ver_count', 't3ver_tstamp', 't3ver_move_id', 't3_origuid', 'l10n_parent', 'l10n_diffsource', 'l10n_state'],
		int $resolveDepth = 0
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

			$mappedRecord[$fieldName] = $this->transformValue($value, $columnConfig, $resolveDepth, $tableName, $record['uid'] ?? 0, $fieldName);
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
	 * @param int $resolveDepth
	 * @return array
	 */
	public function mapRecords(
		string $tableName,
		array $records,
		array $allowedFields = [],
		array $excludedFields = ['tstamp', 'crdate', 'cruser_id', 'hidden', 'deleted', 't3ver_oid', 't3ver_id', 't3ver_wsid', 't3ver_label', 't3ver_state', 't3ver_stage', 't3ver_count', 't3ver_tstamp', 't3ver_move_id', 't3_origuid', 'l10n_parent', 'l10n_diffsource', 'l10n_state'],
		int $resolveDepth = 0
	): array {
		$mappedRecords = [];
		foreach ($records as $record) {
			$mappedRecords[] = $this->mapRecord($tableName, $record, $allowedFields, $excludedFields, $resolveDepth);
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
	 * @param int $resolveDepth
	 * @param string $tableName
	 * @param int $uid
	 * @param string $fieldName
	 * @return mixed
	 */
	protected function transformValue(
		mixed $value,
		array $config,
		int $resolveDepth = 0,
		string $tableName = '',
		int $uid = 0,
		string $fieldName = ''
	): mixed {
		$type = $config['type'] ?? '';

		if ($uid > 0 && $tableName !== '' && $fieldName !== '') {
			$isFAL = ($type === 'inline' || $type === 'group') &&
				(($config['foreign_table'] ?? '') === 'sys_file_reference' ||
					($config['internal_type'] ?? '') === 'file_reference' ||
					str_contains($config['MM'] ?? '', 'sys_file_reference'));

			if ($isFAL) {
				return $this->resolveFileReferences($tableName, $fieldName, $uid);
			}
		}

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
			case 'inline':
				if ($resolveDepth > 0 && isset($config['foreign_table'])) {
					$foreignTable = $config['foreign_table'];
					if (is_string($value) && str_contains($value, ',')) {
						$uids = GeneralUtility::intExplode(',', $value, TRUE);
					} else {
						$uids = [(int) $value];
					}

					$resolvedRecords = [];
					foreach ($uids as $foreignUid) {
						if ($foreignUid <= 0) {
							continue;
						}
						$queryBuilder = $this->connectionPool->getQueryBuilderForTable($foreignTable);
						$foreignRecord = $queryBuilder->select('*')
							->from($foreignTable)
							->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($foreignUid)))
							->executeQuery()
							->fetchAssociative();

						if ($foreignRecord) {
							$resolvedRecords[] = $this->mapRecord($foreignTable, $foreignRecord, [], [], $resolveDepth - 1);
						}
					}

					if (isset($config['maxitems']) && (int) $config['maxitems'] <= 1 && count($resolvedRecords) <= 1) {
						return $resolvedRecords[0] ?? NULL;
					}
					return $resolvedRecords;
				}

				if (isset($config['maxitems']) && (int) $config['maxitems'] > 1) {
					return is_string($value) ? explode(',', $value) : $value;
				}
				return $value;
			default:
				return $value;
		}
	}

	/**
	 * Resolves FAL file references for a given field
	 *
	 * @param string $tableName
	 * @param string $fieldName
	 * @param int $uid
	 * @return array
	 */
	protected function resolveFileReferences(string $tableName, string $fieldName, int $uid): array {
		$fileReferences = $this->resourceFactory->getFileReferenceObjectsByElement(
			$uid,
			$tableName,
			$fieldName
		);

		$resolvedFiles = [];
		foreach ($fileReferences as $fileReference) {
			/** @var FileReference $fileReference */
			$file = $fileReference->getOriginalFile();
			$resolvedFiles[] = [
				'uid' => $fileReference->getUid(),
				'title' => $fileReference->getTitle() ?: ($file->getProperty('title') ?: $file->getName()),
				'description' => $fileReference->getDescription() ?: $file->getProperty('description'),
				'alternative' => $fileReference->getAlternative(),
				'url' => $file->getPublicUrl(),
				'extension' => $file->getExtension(),
				'size' => $file->getSize(),
				'mime_type' => $file->getMimeType(),
			];
		}

		return $resolvedFiles;
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
