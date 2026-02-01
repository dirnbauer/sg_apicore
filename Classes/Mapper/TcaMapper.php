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

use Doctrine\DBAL\Exception;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\StreamFactory;
use TYPO3\CMS\Core\Resource\FileInterface;
use TYPO3\CMS\Core\Resource\FileReference;
use TYPO3\CMS\Core\Resource\FileRepository;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Persistence\Generic\PersistenceManager;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;

/**
 * Service for mapping TYPO3 records to API responses based on TCA and vice versa
 */
class TcaMapper implements SingletonInterface {
	/**
	 * @var array
	 */
	protected array $recordCache = [];

	/**
	 * @param PersistenceManager $persistenceManager
	 * @param ConnectionPool $connectionPool
	 * @param ResourceFactory $resourceFactory
	 * @param FileRepository $fileRepository
	 * @param ContentObjectRenderer $contentObjectRenderer
	 */
	public function __construct(
		protected PersistenceManager $persistenceManager,
		protected ConnectionPool $connectionPool,
		protected ResourceFactory $resourceFactory,
		protected FileRepository $fileRepository,
		protected ContentObjectRenderer $contentObjectRenderer
	) {
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
	 * @param array $fieldConfiguration Map of table names to their field configurations (allowed/excluded)
	 * @param array $renamedFields
	 * @param array $customCallbacks
	 * @return array
	 */
	public function mapRecord(
		string $tableName,
		array $record,
		array $allowedFields = [],
		array $excludedFields = ['tstamp', 'crdate', 'cruser_id', 'hidden', 'deleted', 't3ver_oid', 't3ver_id', 't3ver_wsid', 't3ver_label', 't3ver_state', 't3ver_stage', 't3ver_count', 't3ver_tstamp', 't3ver_move_id', 't3_origuid', 'l10n_parent', 'l10n_diffsource', 'l10n_state'],
		int $resolveDepth = 0,
		array $fieldConfiguration = [],
		array $renamedFields = [],
		array $customCallbacks = []
	): array {
		$mappedRecord = [];
		$tca = $GLOBALS['TCA'][$tableName] ?? [];
		if (empty($tca)) {
			return $record;
		}

		$fieldsToMap = $allowedFields;
		if (isset($fieldConfiguration[$tableName]['allowed']) && is_array($fieldConfiguration[$tableName]['allowed'])) {
			$fieldsToMap = $fieldConfiguration[$tableName]['allowed'];
		}

		$currentExcludedFields = $excludedFields;
		if (isset($fieldConfiguration[$tableName]['excluded']) && is_array($fieldConfiguration[$tableName]['excluded'])) {
			$currentExcludedFields = array_merge($excludedFields, $fieldConfiguration[$tableName]['excluded']);
		}

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
			if (in_array($fieldName, $currentExcludedFields, TRUE)) {
				continue;
			}

			if (!isset($record[$fieldName])) {
				continue;
			}

			$value = $record[$fieldName];
			$columnConfig = $tca['columns'][$fieldName]['config'] ?? [];

			$transformedValue = $this->transformValue(
				$value,
				$columnConfig,
				$resolveDepth,
				$tableName,
				$record['uid'] ?? 0,
				$fieldName,
				$fieldConfiguration
			);

			$targetFieldName = $renamedFields[$fieldName] ?? $fieldName;
			$mappedRecord[$targetFieldName] = $transformedValue;
		}

		foreach ($customCallbacks as $fieldName => $callback) {
			if (is_callable($callback)) {
				$mappedRecord[$fieldName] = $callback($record, $mappedRecord);
			}
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
	 * @param array $fieldConfiguration
	 * @param array $renamedFields
	 * @param array $customCallbacks
	 * @return array
	 */
	public function mapRecords(
		string $tableName,
		array $records,
		array $allowedFields = [],
		array $excludedFields = ['tstamp', 'crdate', 'cruser_id', 'hidden', 'deleted', 't3ver_oid', 't3ver_id', 't3ver_wsid', 't3ver_label', 't3ver_state', 't3ver_stage', 't3ver_count', 't3ver_tstamp', 't3ver_move_id', 't3_origuid', 'l10n_parent', 'l10n_diffsource', 'l10n_state'],
		int $resolveDepth = 0,
		array $fieldConfiguration = [],
		array $renamedFields = [],
		array $customCallbacks = []
	): array {
		$mappedRecords = [];
		foreach ($records as $record) {
			$mappedRecords[] = $this->mapRecord(
				$tableName,
				$record,
				$allowedFields,
				$excludedFields,
				$resolveDepth,
				$fieldConfiguration,
				$renamedFields,
				$customCallbacks
			);
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
	 * @param array $fieldConfiguration
	 * @return mixed
	 * @throws Exception
	 */
	protected function transformValue(
		mixed $value,
		array $config,
		int $resolveDepth = 0,
		string $tableName = '',
		int $uid = 0,
		string $fieldName = '',
		array $fieldConfiguration = []
	): mixed {
		$type = $config['type'] ?? '';

		if ($uid > 0 && $tableName !== '' && $fieldName !== '') {
			$isFAL = ($type === 'inline' || $type === 'group' || $type === 'file') &&
				(($config['foreign_table'] ?? '') === 'sys_file_reference' ||
					($config['internal_type'] ?? '') === 'file_reference' ||
					str_contains($config['MM'] ?? '', 'sys_file_reference') ||
					$type === 'file');

			if ($isFAL) {
				return $this->resolveFileReferences($tableName, $fieldName, $uid);
			}
		}

		if ($value === NULL) {
			return NULL;
		}

		switch ($type) {
			case 'text':
				if (($config['enableRichtext'] ?? FALSE) || ($config['richtextConfiguration'] ?? '')) {
					$value = $this->processRteContent((string) $value);
				}

				return $this->applyContentReplacer((string) $value);
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

				return $this->applyContentReplacer((string) $value);
			case 'number':
				return str_contains($config['format'] ?? '', 'decimal') ? (float) $value : (int) $value;
			case 'select':
			case 'group':
			case 'inline':
				if ($resolveDepth > 0 && isset($config['foreign_table'])) {
					$foreignTable = $config['foreign_table'];
					$resolvedRecords = [];

					if (isset($config['MM'])) {
						// M:M relation via MM table
						$queryBuilder = $this->connectionPool->getQueryBuilderForTable($config['MM']);
						$results = $queryBuilder->select('uid_foreign')
							->from($config['MM'])
							->where($queryBuilder->expr()->eq('uid_local', $queryBuilder->createNamedParameter($uid)))
							->executeQuery()
							->fetchAllAssociative();

						foreach ($results as $mmRecord) {
							$foreignUid = (int) $mmRecord['uid_foreign'];
							if ($foreignUid <= 0) {
								continue;
							}

							$cacheKey = $foreignTable . ':' . $foreignUid;
							if (isset($this->recordCache[$cacheKey])) {
								$foreignRecord = $this->recordCache[$cacheKey];
							} else {
								$queryBuilder = $this->connectionPool->getQueryBuilderForTable($foreignTable);
								$foreignRecord = $queryBuilder->select('*')
									->from($foreignTable)
									->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($foreignUid)))
									->executeQuery()
									->fetchAssociative();
								$this->recordCache[$cacheKey] = $foreignRecord;
							}

							if ($foreignRecord) {
								$resolvedRecords[] = $this->mapRecord(
									$foreignTable,
									$foreignRecord,
									[],
									[],
									$resolveDepth - 1,
									$fieldConfiguration
								);
							}
						}
					} elseif (isset($config['foreign_field'])) {
						// 1:n relation via foreign_field
						$queryBuilder = $this->connectionPool->getQueryBuilderForTable($foreignTable);
						$queryBuilder->select('*')
							->from($foreignTable)
							->where($queryBuilder->expr()->eq($config['foreign_field'], $queryBuilder->createNamedParameter($uid)));

						if (isset($config['foreign_table_field']) && $tableName !== '') {
							$queryBuilder->andWhere(
								$queryBuilder->expr()->eq($config['foreign_table_field'], $queryBuilder->createNamedParameter($tableName))
							);
						}

						if (isset($GLOBALS['TCA'][$foreignTable]['ctrl']['sortby'])) {
							$queryBuilder->orderBy($GLOBALS['TCA'][$foreignTable]['ctrl']['sortby']);
						} elseif (isset($GLOBALS['TCA'][$foreignTable]['ctrl']['default_sortby'])) {
							$queryBuilder->orderBy($GLOBALS['TCA'][$foreignTable]['ctrl']['default_sortby']);
						}

						$foreignRecords = $queryBuilder->executeQuery()->fetchAllAssociative();
						foreach ($foreignRecords as $foreignRecord) {
							$resolvedRecords[] = $this->mapRecord(
								$foreignTable,
								$foreignRecord,
								[],
								[],
								$resolveDepth - 1,
								$fieldConfiguration
							);
						}
					} else {
						// Relation via comma-separated list of UIDs
						if (is_string($value) && str_contains($value, ',')) {
							$uids = GeneralUtility::intExplode(',', $value, TRUE);
						} else {
							$uids = [(int) $value];
						}

						foreach ($uids as $foreignUid) {
							if ($foreignUid <= 0) {
								continue;
							}

							$cacheKey = $foreignTable . ':' . $foreignUid;
							if (isset($this->recordCache[$cacheKey])) {
								$foreignRecord = $this->recordCache[$cacheKey];
							} else {
								$queryBuilder = $this->connectionPool->getQueryBuilderForTable($foreignTable);
								$foreignRecord = $queryBuilder->select('*')
									->from($foreignTable)
									->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($foreignUid)))
									->executeQuery()
									->fetchAssociative();
								$this->recordCache[$cacheKey] = $foreignRecord;
							}

							if ($foreignRecord) {
								$resolvedRecords[] = $this->mapRecord(
									$foreignTable,
									$foreignRecord,
									[],
									[],
									$resolveDepth - 1,
									$fieldConfiguration
								);
							}
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
		$fileReferences = $this->fileRepository->findByRelation(
			$tableName,
			$fieldName,
			$uid
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

	/**
	 * Processes the RTE content to standard HTML
	 *
	 * @param string $content
	 * @return string
	 */
	protected function processRteContent(string $content): string {
		if ($content === '') {
			return '';
		}

		$request = $GLOBALS['TYPO3_REQUEST'] ?? NULL;
		if ($request instanceof \Psr\Http\Message\ServerRequestInterface) {
			$this->contentObjectRenderer->setRequest($request);
		}
		$this->contentObjectRenderer->start([]);

		$parseFuncConf = $GLOBALS['TSFE']->tmpl->setup['lib.']['parseFunc_RTE.'] ?? [];
		if ($request instanceof \Psr\Http\Message\ServerRequestInterface && (empty($parseFuncConf) || count($parseFuncConf) <= 1)) {
			$frontendTypoScript = $request->getAttribute('frontend.typoscript');
			if ($frontendTypoScript instanceof \TYPO3\CMS\Core\TypoScript\FrontendTypoScript) {
				$setup = $frontendTypoScript->getSetupArray();
				$parseFuncConf = $setup['lib.']['parseFunc_RTE.'] ?? [];
				if (empty($parseFuncConf)) {
					$parseFuncConf = $setup['lib.']['parseFunc.'] ?? [];
				}
			}
		}

		if (empty($parseFuncConf)) {
			return $content;
		}

		return $this->contentObjectRenderer->parseFunc($content, $parseFuncConf, '< lib.parseFunc_RTE');
	}

	/**
	 * Applies the content replacer to the given content if the extension is available
	 *
	 * @param string $content
	 * @return string
	 */
	protected function applyContentReplacer(string $content): string {
		if ($content === '' || !class_exists('SGalinski\Citypower\Service\ContentReplacementService')) {
			return $content;
		}

		$contentReplacementService = GeneralUtility::makeInstance(
			'SGalinski\Citypower\Service\ContentReplacementService'
		);
		return $contentReplacementService->replaceContent($content);
	}
}
