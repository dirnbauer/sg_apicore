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

namespace SGalinski\SgApiCore\Controller;

use Doctrine\DBAL\Exception;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SGalinski\SgApiCore\Mapper\TcaMapper;
use SGalinski\SgApiCore\Service\PaginationService;
use SGalinski\SgApiCore\Service\ResponseService;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Generic Controller for Resource CRUD operations (Auto-CRUD)
 */
class ResourceController {
	public function __construct(
		protected ConnectionPool $connectionPool,
		protected TcaMapper $tcaMapper,
		protected ResponseService $responseService,
		protected PaginationService $paginationService,
		protected \SGalinski\SgApiCore\Service\LogService $logService
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
			return $this->responseService->createErrorResponse(
				'Internal Error',
				'Resource configuration missing.',
				500
			);
		}

		$tableName = $resourceConfig['table'];
		$queryBuilder = $this->connectionPool->getQueryBuilderForTable($tableName);
		$queryBuilder->select('*')->from($tableName);

		// Pagination
		$pagination = $this->paginationService->getPaginationParams($request);

		// Filtering (minimal)
		$queryParams = $request->getQueryParams();
		$filters = $queryParams['filter'] ?? [];
		if (is_string($filters)) {
			// Some clients might send filters as a string, e.g. filter[name]=value
			// Or even name=value directly if they misinterpret the documentation
			if (str_contains($filters, '[') && str_contains($filters, ']')) {
				parse_str($filters, $parsedFilters);
				if (isset($parsedFilters['filter']) && is_array($parsedFilters['filter'])) {
					$filters = $parsedFilters['filter'];
				}
			} elseif (str_contains($filters, '=')) {
				parse_str($filters, $parsedFilters);
				$filters = $parsedFilters;
			}
		}

		if (is_array($filters) && count($filters) > 0) {
			foreach ($filters as $field => $value) {
				// Only filter by whitelisted fields
				if (!empty($resourceConfig['readFields']) && !in_array($field, $resourceConfig['readFields'], TRUE)) {
					// Also check if uid or pid is requested, which is always allowed if not explicitly restricted
					if ($field !== 'uid' && $field !== 'pid') {
						continue;
					}
				}

				// Basic check if field exists in TCA
				if (!isset($GLOBALS['TCA'][$tableName]['columns'][$field]) && $field !== 'uid' && $field !== 'pid') {
					continue;
				}

				if (is_array($value)) {
					$queryBuilder->andWhere(
						$queryBuilder->expr()->in($field, $queryBuilder->createNamedParameter($value, Connection::PARAM_STR_ARRAY))
					);
				} else {
					$queryBuilder->andWhere(
						$queryBuilder->expr()->eq($field, $queryBuilder->createNamedParameter($value))
					);
				}
			}
		}

		// Sorting
		if (isset($queryParams['sort']) && is_string($queryParams['sort'])) {
			$sortField = $queryParams['sort'];
			$sortOrder = 'ASC';
			if (str_starts_with($sortField, '-')) {
				$sortField = substr($sortField, 1);
				$sortOrder = 'DESC';
			}

			// Validate sort field
			if (isset($GLOBALS['TCA'][$tableName]['columns'][$sortField]) || $sortField === 'uid') {
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
		$records = $result->fetchAllAssociative();

		$mappedRecords = $this->tcaMapper->mapRecords($tableName, $records, $resourceConfig['readFields']);

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
			return $this->responseService->createErrorResponse(
				'Internal Error',
				'Resource configuration missing.',
				500
			);
		}

		$tableName = $resourceConfig['table'];
		$idField = $resourceConfig['idField'] ?? 'uid';

		$queryBuilder = $this->connectionPool->getQueryBuilderForTable($tableName);
		$record = $queryBuilder->select('*')
			->from($tableName)
			->where($queryBuilder->expr()->eq($idField, $queryBuilder->createNamedParameter($id)))
			->executeQuery()
			->fetchAssociative();

		if (!$record) {
			return $this->responseService->createErrorResponse('Not Found', 'Resource not found.', 404);
		}

		$mappedRecord = $this->tcaMapper->mapRecord($tableName, $record, $resourceConfig['readFields']);
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
			return $this->responseService->createErrorResponse(
				'Internal Error',
				'Resource configuration missing.',
				500
			);
		}

		$tableName = $resourceConfig['table'];
		$data = $request->getParsedBody();

		if (!is_array($data)) {
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
				'NEW1' => $filteredData
			]
		];

		$this->initializeBackendUser();
		$dataHandler = GeneralUtility::makeInstance(DataHandler::class);
		$dataHandler->admin = TRUE;
		$dataHandler->dontProcessTransformations = TRUE;
		$dataHandler->start($dataMap, []);
		$dataHandler->process_datamap();

		if (count($dataHandler->errorLog) > 0) {
			foreach ($dataHandler->errorLog as $error) {
				$this->logService->logError('DataHandler Error: ' . $error, []);
			}

			return $this->responseService->createErrorResponse(
				'Internal Error',
				'An error occurred while processing the data.',
				500
			);
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
			return $this->responseService->createErrorResponse(
				'Internal Error',
				'Resource configuration missing.',
				500
			);
		}

		$tableName = $resourceConfig['table'];
		$idField = $resourceConfig['idField'] ?? 'uid';
		$data = $request->getParsedBody();

		if (!is_array($data)) {
			return $this->responseService->createErrorResponse('Bad Request', 'Invalid JSON body.', 400);
		}

		// Check if a record exists
		$queryBuilder = $this->connectionPool->getQueryBuilderForTable($tableName);
		$uid = (int) $queryBuilder->select('uid')
			->from($tableName)
			->where($queryBuilder->expr()->eq($idField, $queryBuilder->createNamedParameter($id)))
			->executeQuery()
			->fetchOne();

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
				$uid => $filteredData
			]
		];

		$this->initializeBackendUser();
		$dataHandler = GeneralUtility::makeInstance(DataHandler::class);
		$dataHandler->admin = TRUE;
		$dataHandler->dontProcessTransformations = TRUE;
		$dataHandler->start($dataMap, []);
		$dataHandler->process_datamap();

		if (count($dataHandler->errorLog) > 0) {
			foreach ($dataHandler->errorLog as $error) {
				$this->logService->logError('DataHandler Error: ' . $error, []);
			}

			return $this->responseService->createErrorResponse(
				'Internal Error',
				'An error occurred while processing the data.',
				500
			);
		}

		return $this->getAction($request, (string) $uid);
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
			return $this->responseService->createErrorResponse(
				'Internal Error',
				'Resource configuration missing.',
				500
			);
		}

		$tableName = $resourceConfig['table'];
		$idField = $resourceConfig['idField'] ?? 'uid';

		// Check if a record exists
		$queryBuilder = $this->connectionPool->getQueryBuilderForTable($tableName);
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
				$uid => ['delete' => 1]
			]
		];

		$this->initializeBackendUser();
		$dataHandler = GeneralUtility::makeInstance(DataHandler::class);
		$dataHandler->admin = TRUE;
		$dataHandler->dontProcessTransformations = TRUE;
		$dataHandler->start([], $cmdMap);
		$dataHandler->process_cmdmap();

		if (count($dataHandler->errorLog) > 0) {
			return $this->responseService->createErrorResponse(
				'Internal Error',
				'DataHandler errors: ' . implode(', ', $dataHandler->errorLog),
				500
			);
		}

		return new Response('', 204, [
			'X-Content-Type-Options' => 'nosniff',
			'X-Frame-Options' => 'DENY',
		]);
	}

	/**
	 * Initializes a dummy backend user if none exists
	 *
	 * @return void
	 */
	protected function initializeBackendUser(): void {
		if (($GLOBALS['BE_USER'] ?? NULL) instanceof BackendUserAuthentication) {
			return;
		}

		$GLOBALS['BE_USER'] = GeneralUtility::makeInstance(BackendUserAuthentication::class);
		$GLOBALS['BE_USER']->user['admin'] = 1;
	}
}
