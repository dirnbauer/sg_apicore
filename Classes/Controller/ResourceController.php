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

namespace SGalinski\SgApiCore\Controller;

use Doctrine\DBAL\Exception;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SGalinski\SgApiCore\Configuration\ExtensionConfiguration;
use SGalinski\SgApiCore\Mapper\TcaMapper;
use SGalinski\SgApiCore\Service\LogService;
use SGalinski\SgApiCore\Service\PaginationService;
use SGalinski\SgApiCore\Service\ResponseService;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Versioning\VersionState;

/**
 * Generic Controller for Resource CRUD operations (Auto-CRUD)
 */
class ResourceController {
	public function __construct(
		protected ConnectionPool $connectionPool,
		protected TcaMapper $tcaMapper,
		protected ResponseService $responseService,
		protected PaginationService $paginationService,
		protected LogService $logService,
		protected ExtensionConfiguration $extensionConfiguration,
		protected LanguageServiceFactory $languageServiceFactory,
		protected Typo3Version $typo3Version
	) {
	}

	/**
	 * Generic List Action for Resources
	 *
	 * @param ServerRequestInterface $request
	 * @return ResponseInterface
	 * @throws Exception
	 */
	public function listAction(ServerRequestInterface $request): ResponseInterface {
		$resourceConfig = $request->getAttribute('api.resource');
		if (!$resourceConfig) {
			return $this->responseService->createErrorResponse('Internal Error', 'Resource configuration missing.', 500);
		}

		$tableName = $resourceConfig['table'];
		if (!\is_string($tableName)) {
			return $this->responseService->createErrorResponse('Internal Error', 'Resource configuration missing.', 500);
		}
		$queryBuilder = $this->connectionPool->getQueryBuilderForTable($tableName);
		$this->applyResourceVisibilityRestrictions($queryBuilder, $resourceConfig);
		$queryBuilder->select('*')->from($tableName);

		// Pagination
		$pagination = $this->paginationService->getPaginationParams($request);

		// Filtering (minimal)
		$queryParams = $request->getQueryParams();
		$filters = $queryParams['filter'] ?? [];
		if (\is_string($filters)) {
			// Some clients might send filters as a string, e.g. filter[name]=value
			// Or even name=value directly if they misinterpret the documentation
			if (str_contains($filters, '[') && str_contains($filters, ']')) {
				parse_str($filters, $parsedFilters);
				if (isset($parsedFilters['filter']) && \is_array($parsedFilters['filter'])) {
					$filters = $parsedFilters['filter'];
				}
			} elseif (str_contains($filters, '=')) {
				parse_str($filters, $parsedFilters);
				$filters = $parsedFilters;
			}
		}

		if (\is_array($filters) && \count($filters) > 0) {
			foreach ($filters as $field => $value) {
				if (!\is_string($field)) {
					continue;
				}
				// Only filter by whitelisted fields
				if (!empty($resourceConfig['readFields']) && !\in_array($field, $resourceConfig['readFields'], TRUE)) {
					// Also check if uid or pid is requested, which is always allowed if not explicitly restricted
					if ($field !== 'uid' && $field !== 'pid') {
						continue;
					}
				}

				// Basic check if field exists in TCA
				if (!$this->hasTcaColumn($tableName, $field) && $field !== 'uid' && $field !== 'pid') {
					continue;
				}

				if (\is_array($value)) {
					$queryBuilder->andWhere(
						$queryBuilder->expr()->in($field, $queryBuilder->createNamedParameter($value, Connection::PARAM_STR_ARRAY))
					);
				} else {
					$queryBuilder->andWhere($queryBuilder->expr()->eq($field, $queryBuilder->createNamedParameter($value)));
				}
			}
		}

		// Sorting
		if (isset($queryParams['sort']) && \is_string($queryParams['sort'])) {
			$sortField = $queryParams['sort'];
			$sortOrder = 'ASC';
			if (str_starts_with($sortField, '-')) {
				$sortField = substr($sortField, 1);
				$sortOrder = 'DESC';
			}

			// Validate sort field
			if ($this->hasTcaColumn($tableName, $sortField) || $sortField === 'uid') {
				$queryBuilder->orderBy($sortField, $sortOrder);
			}
		}

		// Count total for pagination meta BEFORE applying offset/limit
		$total = 0;
		$skipCount = FALSE;
		if (isset($queryParams['skipCount'])) {
			$skipCount = filter_var($queryParams['skipCount'], FILTER_VALIDATE_BOOLEAN);
		}

		if (!$skipCount) {
			$countQueryBuilder = clone $queryBuilder;
			$countQueryBuilder->getConcreteQueryBuilder()->resetOrderBy();
			$total = (int) $countQueryBuilder->count('*')
				->executeQuery()
				->fetchOne();
		}

		// Apply pagination to the main query
		$queryBuilder->setFirstResult($pagination['offset'])->setMaxResults($pagination['limit']);

		$result = $queryBuilder->executeQuery();
		$records = $this->normalizeRecordList($result->fetchAllAssociative());
		$records = $this->applyWorkspaceVisibilityToRecords($tableName, $records);

		$mappedRecords = $this->tcaMapper->mapRecords(
			$tableName,
			$records,
			$resourceConfig['readFields'],
			resolveDepth: 1, // Always resolve depth 1 for resource lists if possible? Or keep 0?
			fieldConfiguration: $resourceConfig['fieldConfiguration'] ?? []
		);

		return $this->responseService->createSuccessResponse(
			$mappedRecords,
			$this->paginationService->buildPaginationMeta($total, $pagination['offset'], $pagination['limit'])
		);
	}

	/**
	 * Generic Get Action for Resources
	 *
	 * @param ServerRequestInterface $request
	 * @param string $id
	 * @return ResponseInterface
	 * @throws Exception
	 */
	public function getAction(ServerRequestInterface $request, string $id): ResponseInterface {
		$resourceConfig = $request->getAttribute('api.resource');
		if (!$resourceConfig) {
			return $this->responseService->createErrorResponse('Internal Error', 'Resource configuration missing.', 500);
		}

		$tableName = $resourceConfig['table'];
		if (!\is_string($tableName)) {
			return $this->responseService->createErrorResponse('Internal Error', 'Resource configuration missing.', 500);
		}
		$idField = $resourceConfig['idField'] ?? 'uid';

		$queryBuilder = $this->connectionPool->getQueryBuilderForTable($tableName);
		$this->applyResourceVisibilityRestrictions($queryBuilder, $resourceConfig);
		$record = $queryBuilder->select('*')
			->from($tableName)
			->where($queryBuilder->expr()->eq($idField, $queryBuilder->createNamedParameter($id)))
			->executeQuery()
			->fetchAssociative();
		$record = $this->applyWorkspaceVisibilityToRecord($tableName, $record, FALSE);

		if (!$record) {
			return $this->responseService->createErrorResponse('Not Found', 'Resource not found.', 404);
		}

		$mappedRecord = $this->tcaMapper->mapRecord(
			$tableName,
			$record,
			$resourceConfig['readFields'],
			resolveDepth: 1, // Always resolve depth 1 for resource details if possible?
			fieldConfiguration: $resourceConfig['fieldConfiguration'] ?? []
		);
		return $this->responseService->createSuccessResponse($mappedRecord);
	}

	/**
	 * Generic Create Action for Resources
	 *
	 * @param ServerRequestInterface $request
	 * @return ResponseInterface
	 * @throws Exception
	 */
	public function createAction(ServerRequestInterface $request): ResponseInterface {
		$resourceConfig = $request->getAttribute('api.resource');
		if (!$resourceConfig) {
			return $this->responseService->createErrorResponse('Internal Error', 'Resource configuration missing.', 500);
		}

		$tableName = $resourceConfig['table'];
		$data = $request->getParsedBody();

		if (!\is_array($data)) {
			return $this->responseService->createErrorResponse('Bad Request', 'Invalid JSON body.', 400);
		}

		// Filter allowed write fields
		$filteredData = $this->tcaMapper->mapDataForDatabase($tableName, $data, $resourceConfig['writeFields']);

		// Handle PID for new records
		if (!isset($filteredData['pid'])) {
			$pid = (int) ($data['pid'] ?? 0);
			if ($pid <= 0) {
				$tenantContext = $request->getAttribute('api.tenant');
				$pid = $tenantContext ? $tenantContext->getSite()->getRootPageId() : 0;
			}
			$filteredData['pid'] = $pid;
		}

		// Persistent via DataHandler
		$dataMap = [
			$tableName => [
				'NEW1' => $filteredData,
			],
		];
		$this->expandFileReferencePayloads($tableName, 'NEW1', $dataMap, (int) $filteredData['pid']);

		$writeBackendUserId = $this->extensionConfiguration->getApiResourceWriteBackendUserId();
		$errorResponse = $this->initializeBackendUser(
			$writeBackendUserId,
			$this->extensionConfiguration->getApiResourceWriteWorkspaceId()
		);
		if ($errorResponse instanceof ResponseInterface) {
			return $errorResponse;
		}

		$dataHandler = GeneralUtility::makeInstance(DataHandler::class);
		$dataHandler->bypassAccessCheckForRecords = $writeBackendUserId <= 0 && !$this->hasAuthenticatedBackendUser();
		$dataHandler->dontProcessTransformations = TRUE;
		$dataHandler->start($dataMap, []);
		$dataHandler->process_datamap();

		if (\count($dataHandler->errorLog) > 0) {
			return $this->createDataHandlerErrorResponse($dataHandler->errorLog);
		}

		$newUid = $dataHandler->substNEWwithIDs['NEW1'] ?? 0;
		return $this->getAction($request, (string) $newUid);
	}

	/**
	 * Generic Update Action for Resources
	 *
	 * @param ServerRequestInterface $request
	 * @param string $id
	 * @return ResponseInterface
	 * @throws Exception
	 */
	public function updateAction(ServerRequestInterface $request, string $id): ResponseInterface {
		$resourceConfig = $request->getAttribute('api.resource');
		if (!$resourceConfig) {
			return $this->responseService->createErrorResponse('Internal Error', 'Resource configuration missing.', 500);
		}

		$tableName = $resourceConfig['table'];
		$idField = $resourceConfig['idField'] ?? 'uid';
		$data = $request->getParsedBody();

		if (!\is_array($data)) {
			return $this->responseService->createErrorResponse('Bad Request', 'Invalid JSON body.', 400);
		}

		// Check if a record exists
		$queryBuilder = $this->connectionPool->getQueryBuilderForTable($tableName);
		$this->applyResourceVisibilityRestrictions($queryBuilder, $resourceConfig);
		$record = $queryBuilder->select('uid', 'pid')
			->from($tableName)
			->where($queryBuilder->expr()->eq($idField, $queryBuilder->createNamedParameter($id)))
			->executeQuery()
			->fetchAssociative();

		$uid = (int) ($record['uid'] ?? 0);
		if ($uid <= 0) {
			return $this->responseService->createErrorResponse('Not Found', 'Resource not found.', 404);
		}

		// Filter allowed writing fields
		$filteredData = $this->tcaMapper->mapDataForDatabase($tableName, $data, $resourceConfig['writeFields']);

		if (empty($filteredData)) {
			return $this->getAction($request, (string) $uid);
		}

		$dataMap = [
			$tableName => [
				$uid => $filteredData,
			],
		];
		$this->expandFileReferencePayloads($tableName, $uid, $dataMap, (int) ($filteredData['pid'] ?? $record['pid'] ?? 0));

		$writeBackendUserId = $this->extensionConfiguration->getApiResourceWriteBackendUserId();
		$errorResponse = $this->initializeBackendUser(
			$writeBackendUserId,
			$this->extensionConfiguration->getApiResourceWriteWorkspaceId()
		);
		if ($errorResponse instanceof ResponseInterface) {
			return $errorResponse;
		}

		$dataHandler = GeneralUtility::makeInstance(DataHandler::class);
		$dataHandler->bypassAccessCheckForRecords = $writeBackendUserId <= 0 && !$this->hasAuthenticatedBackendUser();
		$dataHandler->dontProcessTransformations = TRUE;
		$dataHandler->start($dataMap, []);
		$dataHandler->process_datamap();

		if (\count($dataHandler->errorLog) > 0) {
			return $this->createDataHandlerErrorResponse($dataHandler->errorLog);
		}

		return $this->getAction($request, (string) $uid);
	}

	/**
	 * @param array<int, array<string, mixed>> $records
	 * @return array<int, array<string, mixed>>
	 */
	protected function applyWorkspaceVisibilityToRecords(string $tableName, array $records): array {
		$visibleRecords = [];
		$seenRecordIds = [];
		foreach ($records as $record) {
			$visibleRecord = $this->applyWorkspaceVisibilityToRecord($tableName, $record, TRUE);
			if ($visibleRecord === NULL) {
				continue;
			}
			$uid = $this->toInteger($visibleRecord['uid'] ?? 0);
			if ($uid > 0 && isset($seenRecordIds[$uid])) {
				continue;
			}
			if ($uid > 0) {
				$seenRecordIds[$uid] = TRUE;
			}
			$visibleRecords[] = $visibleRecord;
		}
		return $visibleRecords;
	}

	/**
	 * @param array<int, array<array-key, mixed>> $records
	 * @return array<int, array<string, mixed>>
	 */
	protected function normalizeRecordList(array $records): array {
		$normalizedRecords = [];
		foreach ($records as $record) {
			$normalizedRecords[] = $this->normalizeRecord($record);
		}
		return $normalizedRecords;
	}

	/**
	 * @param array<array-key, mixed> $record
	 * @return array<string, mixed>
	 */
	protected function normalizeRecord(array $record): array {
		$normalizedRecord = [];
		foreach ($record as $fieldName => $value) {
			if (\is_string($fieldName)) {
				$normalizedRecord[$fieldName] = $value;
			}
		}
		return $normalizedRecord;
	}

	/**
	 * Applies TYPO3 workspace visibility rules to raw database rows.
	 *
	 * Raw QueryBuilder reads do not apply workspace overlays. For live workspace, draft
	 * rows must be hidden. For custom workspaces, live rows are overlaid with their
	 * workspace version so API saves return the staged record values.
	 *
	 * @param array<string, mixed>|false|null $record
	 * @return array<string, mixed>|null
	 */
	protected function applyWorkspaceVisibilityToRecord(string $tableName, array|false|null $record, bool $skipVersionRows): ?array {
		if (!\is_array($record)) {
			return NULL;
		}

		$workspaceId = $this->getCurrentWorkspaceId();
		$recordWorkspaceId = $this->toInteger($record['t3ver_wsid'] ?? 0);
		$versionParentId = $this->toInteger($record['t3ver_oid'] ?? 0);

		if ($workspaceId <= 0) {
			return $recordWorkspaceId > 0 ? NULL : $record;
		}

		if ($recordWorkspaceId > 0) {
			if ($recordWorkspaceId !== $workspaceId || $this->isWorkspaceDeletePlaceholder($record)) {
				return NULL;
			}
			if ($skipVersionRows && $versionParentId > 0) {
				return NULL;
			}
			if ($versionParentId > 0) {
				$record['_ORIG_uid'] = $record['uid'];
				$record['uid'] = $versionParentId;
			}
			return $record;
		}

		BackendUtility::workspaceOL($tableName, $record, $workspaceId);
		if (!\is_array($record)) {
			return NULL;
		}
		$record = $this->normalizeRecord($record);
		if ($this->isWorkspaceDeletePlaceholder($record)) {
			return NULL;
		}
		return $record;
	}

	/**
	 * @param array<string, mixed> $record
	 */
	protected function isWorkspaceDeletePlaceholder(array $record): bool {
		return VersionState::tryFrom($this->toInteger($record['t3ver_state'] ?? 0)) === VersionState::DELETE_PLACEHOLDER;
	}

	protected function toInteger(mixed $value): int {
		if (\is_int($value)) {
			return $value;
		}
		if (\is_float($value) || \is_numeric($value)) {
			return (int) $value;
		}
		return 0;
	}

	protected function hasTcaColumn(string $tableName, string $fieldName): bool {
		$tableConfiguration = $GLOBALS['TCA'][$tableName] ?? NULL;
		if (!\is_array($tableConfiguration)) {
			return FALSE;
		}
		$columns = $tableConfiguration['columns'] ?? NULL;
		return \is_array($columns) && isset($columns[$fieldName]);
	}

	/**
	 * @return array<string, mixed>
	 */
	protected function getTcaFieldConfiguration(string $tableName, string $fieldName): array {
		$tableConfiguration = $GLOBALS['TCA'][$tableName] ?? NULL;
		if (!\is_array($tableConfiguration)) {
			return [];
		}
		$columns = $tableConfiguration['columns'] ?? NULL;
		if (!\is_array($columns)) {
			return [];
		}
		$columnConfiguration = $columns[$fieldName] ?? NULL;
		if (!\is_array($columnConfiguration)) {
			return [];
		}
		$fieldConfiguration = $columnConfiguration['config'] ?? NULL;
		return \is_array($fieldConfiguration) ? $this->normalizeRecord($fieldConfiguration) : [];
	}

	protected function getCurrentWorkspaceId(): int {
		$backendUser = $GLOBALS['BE_USER'] ?? NULL;
		return $backendUser instanceof BackendUserAuthentication ? (int) $backendUser->workspace : 0;
	}

	/**
	 * Generic Delete Action for Resources
	 *
	 * @param ServerRequestInterface $request
	 * @param string $id
	 * @return ResponseInterface
	 * @throws Exception
	 */
	public function deleteAction(ServerRequestInterface $request, string $id): ResponseInterface {
		$resourceConfig = $request->getAttribute('api.resource');
		if (!$resourceConfig) {
			return $this->responseService->createErrorResponse('Internal Error', 'Resource configuration missing.', 500);
		}

		$tableName = $resourceConfig['table'];
		$idField = $resourceConfig['idField'] ?? 'uid';

		// Check if a record exists
		$queryBuilder = $this->connectionPool->getQueryBuilderForTable($tableName);
		$this->applyResourceVisibilityRestrictions($queryBuilder, $resourceConfig);
		$uid = (int) $queryBuilder->select('uid')
			->from($tableName)
			->where($queryBuilder->expr()->eq($idField, $queryBuilder->createNamedParameter($id)))
			->executeQuery()
			->fetchOne();

		if ($uid <= 0) {
			return $this->responseService->createErrorResponse('Not Found', 'Resource not found.', 404);
		}

		$cmdMap = [
			$tableName => [
				$uid => ['delete' => 1],
			],
		];

		$writeBackendUserId = $this->extensionConfiguration->getApiResourceWriteBackendUserId();
		$errorResponse = $this->initializeBackendUser(
			$writeBackendUserId,
			$this->extensionConfiguration->getApiResourceWriteWorkspaceId()
		);
		if ($errorResponse instanceof ResponseInterface) {
			return $errorResponse;
		}

		$deleteMode = (string) ($resourceConfig['deleteMode'] ?? 'soft');
		$hardDelete = $deleteMode === 'hard';
		if ($hardDelete) {
			$connection = $this->connectionPool->getConnectionForTable($tableName);
			$connection->delete($tableName, ['uid' => $uid]);
		} else {
			$dataHandler = GeneralUtility::makeInstance(DataHandler::class);
			$dataHandler->bypassAccessCheckForRecords = $writeBackendUserId <= 0 && !$this->hasAuthenticatedBackendUser();
			$dataHandler->dontProcessTransformations = TRUE;
			$dataHandler->start([], $cmdMap);
			$dataHandler->process_cmdmap();

			if (\count($dataHandler->errorLog) > 0) {
				return $this->createDataHandlerErrorResponse($dataHandler->errorLog);
			}
		}

		return new Response(NULL, 204, [
			'X-Content-Type-Options' => 'nosniff',
			'X-Frame-Options' => 'DENY',
		]);
	}

	/**
	 * Initializes the backend user context for resource write operations
	 *
	 * @param int $writeBackendUserId
	 * @param int $writeWorkspaceId
	 * @return ResponseInterface|null
	 */
	protected function initializeBackendUser(int $writeBackendUserId, int $writeWorkspaceId): ?ResponseInterface {
		$existingBackendUser = $GLOBALS['BE_USER'] ?? NULL;
		if ($existingBackendUser instanceof BackendUserAuthentication) {
			$this->applyWorkspace($existingBackendUser, $writeWorkspaceId);
			return NULL;
		}

		/** @var BackendUserAuthentication $backendUser */
		$backendUser = GeneralUtility::makeInstance(BackendUserAuthentication::class);
		if ($writeBackendUserId > 0) {
			$backendUser->setBeUserByUid($writeBackendUserId);
			if (empty($backendUser->user)) {
				return $this->responseService->createErrorResponse(
					'Internal Error',
					'Configured API resource write backend user (uid ' . $writeBackendUserId . ') not found or inactive.',
					500
				);
			}

			$backendUser->fetchGroupData();
			$backendUser->workspaceInit();
			$this->applyWorkspace($backendUser, $writeWorkspaceId);
			$GLOBALS['BE_USER'] = $backendUser;
			$this->initializeLanguageService();
			return NULL;
		}

		$backendUser->user['admin'] = 1;
		$backendUser->user['uid'] = 0;
		$this->applyWorkspace($backendUser, $writeWorkspaceId);
		$GLOBALS['BE_USER'] = $backendUser;
		$this->initializeLanguageService();
		return NULL;
	}

	protected function applyWorkspace(BackendUserAuthentication $backendUser, int $writeWorkspaceId): void {
		if ($writeWorkspaceId < 0) {
			return;
		}

		$backendUser->setWorkspace($writeWorkspaceId);
	}

	/**
	 * @param array $errors
	 * @return ResponseInterface
	 */
	protected function createDataHandlerErrorResponse(array $errors): ResponseInterface {
		foreach ($errors as $error) {
			$this->logService->logError('DataHandler Error: ' . $error, []);
		}

		return $this->responseService->createErrorResponse(
			'Internal Error',
			'An error occurred while processing the data.',
			500,
			additionalData: ['errors' => array_values($errors)]
		);
	}

	protected function initializeLanguageService(): void {
		if (($GLOBALS['LANG'] ?? NULL) instanceof LanguageService) {
			return;
		}

		$GLOBALS['LANG'] = $this->languageServiceFactory->create('default');
	}

	protected function hasAuthenticatedBackendUser(): bool {
		$backendUser = $GLOBALS['BE_USER'] ?? NULL;
		return $backendUser instanceof BackendUserAuthentication && (int) ($backendUser->user['uid'] ?? 0) > 0;
	}

	/**
	 * Converts API file payloads into DataHandler sys_file_reference child records.
	 *
	 * @param array<string, array<int|string, array<string, mixed>>> $dataMap
	 */
	protected function expandFileReferencePayloads(string $tableName, int|string $recordId, array &$dataMap, int $pid): void {
		$recordData = $dataMap[$tableName][$recordId] ?? [];
		if (!\is_array($recordData)) {
			return;
		}

		foreach ($recordData as $fieldName => $value) {
			if (!\is_string($fieldName)) {
				continue;
			}
			$fieldConfiguration = $this->getTcaFieldConfiguration($tableName, $fieldName);
			if (($fieldConfiguration['type'] ?? '') !== 'file' || !\is_array($value)) {
				continue;
			}

			$referenceIds = [];
			foreach ($value as $index => $filePayload) {
				if (\is_numeric($filePayload)) {
					$referenceIds[] = (string) (int) $filePayload;
					continue;
				}
				if (!\is_array($filePayload)) {
					continue;
				}

				$fileUid = (int) ($filePayload['uid_local'] ?? $filePayload['fileUid'] ?? 0);
				if ($fileUid <= 0 && isset($filePayload['uid'])) {
					$referenceIds[] = (string) (int) $filePayload['uid'];
					continue;
				}
				if ($fileUid <= 0) {
					continue;
				}

				$newReferenceId = 'NEW' . substr(hash('sha1', $tableName . $recordId . $fieldName . $index . microtime(TRUE)), 0, 12);
				$referenceIds[] = $newReferenceId;
				$fileReferenceData = [
					'pid' => $pid,
					'uid_local' => $fileUid,
					'tablenames' => $tableName,
					'fieldname' => $fieldName,
					'table_local' => 'sys_file',
				];

				foreach (['title', 'alternative', 'description', 'link', 'showinpreview'] as $referenceFieldName) {
					if (array_key_exists($referenceFieldName, $filePayload)) {
						$fileReferenceData[$referenceFieldName] = $filePayload[$referenceFieldName];
					}
				}
				if (array_key_exists('crop', $filePayload)) {
					$fileReferenceData['crop'] = \is_array($filePayload['crop'])
						? json_encode($filePayload['crop'], JSON_THROW_ON_ERROR)
						: (string) $filePayload['crop'];
				}

				$dataMap['sys_file_reference'][$newReferenceId] = $fileReferenceData;
			}

			$dataMap[$tableName][$recordId][$fieldName] = implode(',', $referenceIds);
		}
	}

	protected function applyResourceVisibilityRestrictions(QueryBuilder $queryBuilder, array $resourceConfig): void {
		if (empty($resourceConfig['includeDisabled'])) {
			return;
		}

		$queryBuilder->getRestrictions()
			->removeAll()
			->add(GeneralUtility::makeInstance(DeletedRestriction::class));
	}
}
