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

namespace SGalinski\SgApiCore\Tests\Unit\Controller;

use Doctrine\DBAL\Result;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Http\Message\ServerRequestInterface;
use SGalinski\SgApiCore\Configuration\ExtensionConfiguration;
use SGalinski\SgApiCore\Context\TenantContext;
use SGalinski\SgApiCore\Controller\ResourceController;
use SGalinski\SgApiCore\Mapper\TcaMapper;
use SGalinski\SgApiCore\Service\PaginationService;
use SGalinski\SgApiCore\Service\ResponseService;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Test case for ResourceController
 */
class ResourceControllerTest extends UnitTestCase {
	protected ResourceController $controller;
	protected ConnectionPool|MockObject $connectionPool;
	protected TcaMapper|MockObject $tcaMapper;
	protected ResponseService|MockObject $responseService;
	protected PaginationService|MockObject $paginationService;
	protected \SGalinski\SgApiCore\Service\LogService|MockObject $logService;
	protected ExtensionConfiguration|MockObject $extensionConfiguration;

	protected function setUp(): void {
		parent::setUp();
		$this->resetSingletonInstances = TRUE;
		$this->connectionPool = $this->createStub(ConnectionPool::class);
		$this->tcaMapper = $this->createStub(TcaMapper::class);
		$this->responseService = $this->createStub(ResponseService::class);
		$this->paginationService = $this->createStub(PaginationService::class);
		$this->extensionConfiguration = $this->createStub(ExtensionConfiguration::class);
		$this->extensionConfiguration->method('getApiResourceWriteBackendUserId')->willReturn(0);

		// Mock LogManager to avoid singleton issues in tests
		$logManager = $this->createStub(LogManager::class);
		GeneralUtility::setSingletonInstance(LogManager::class, $logManager);

		// Mock BE_USER
		$GLOBALS['BE_USER'] = $this->createStub(BackendUserAuthentication::class);

		$this->logService = $this->createStub(\SGalinski\SgApiCore\Service\LogService::class);

		$this->controller = new ResourceController(
			$this->connectionPool,
			$this->tcaMapper,
			$this->responseService,
			$this->paginationService,
			$this->logService,
			$this->extensionConfiguration
		);
	}

	protected function tearDown(): void {
		unset($GLOBALS['BE_USER']);
		unset($GLOBALS['TCA']['tt_content']);
		parent::tearDown();
	}

	public function testListActionReturnsMappedRecords(): void {
		$GLOBALS['TCA']['tt_content']['columns']['header'] = [];
		$request = $this->createStub(ServerRequestInterface::class);
		$resourceConfig = [
			'table' => 'tt_content',
			'readFields' => ['header']
		];
		$request->method('getAttribute')->with('api.resource')->willReturn($resourceConfig);
		$request->method('getQueryParams')->willReturn([]);

		$queryBuilder = $this->createStub(QueryBuilder::class);
		$this->connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);

		$queryBuilder->method('select')->willReturn($queryBuilder);
		$queryBuilder->method('from')->willReturn($queryBuilder);
		$queryBuilder->method('setFirstResult')->willReturn($queryBuilder);
		$queryBuilder->method('setMaxResults')->willReturn($queryBuilder);
		$concreteQueryBuilder = $this->createStub(\Doctrine\DBAL\Query\QueryBuilder::class);
		$queryBuilder->method('getConcreteQueryBuilder')->willReturn($concreteQueryBuilder);
		$queryBuilder->method('count')->willReturn($queryBuilder);

		$result = $this->createStub(Result::class);
		$queryBuilder->method('executeQuery')->willReturn($result);
		$records = [['uid' => 1, 'header' => 'Test']];
		$result->method('fetchAllAssociative')->willReturn($records);

		$this->paginationService->method('getPaginationParams')->willReturn(['offset' => 0, 'limit' => 10]);
		$this->tcaMapper->method('mapRecords')->willReturn($records);

		$this->responseService->method('createSuccessResponse')->willReturnCallback(
			fn ($data) => new JsonResponse($data)
		);

		$response = $this->controller->listAction($request);

		$this->assertInstanceOf(JsonResponse::class, $response);
		$this->assertEquals(200, $response->getStatusCode());
		$this->assertEquals(json_encode($records), (string) $response->getBody());
	}

	public function testListActionFiltersWithArray(): void {
		$GLOBALS['TCA']['tt_content']['columns']['header'] = [];
		$request = $this->createStub(ServerRequestInterface::class);
		$resourceConfig = [
			'table' => 'tt_content',
			'readFields' => ['header']
		];
		$request->method('getAttribute')->with('api.resource')->willReturn($resourceConfig);
		$request->method('getQueryParams')->willReturn(['filter' => ['header' => 'Test']]);

		$queryBuilder = $this->createMock(QueryBuilder::class);
		$this->connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);

		$expressionBuilder = $this->createMock(ExpressionBuilder::class);
		$queryBuilder->method('expr')->willReturn($expressionBuilder);
		$queryBuilder->method('select')->willReturn($queryBuilder);
		$queryBuilder->method('from')->willReturn($queryBuilder);
		$queryBuilder->method('setFirstResult')->willReturn($queryBuilder);
		$queryBuilder->method('setMaxResults')->willReturn($queryBuilder);
		$queryBuilder->method('andWhere')->willReturn($queryBuilder);
		$concreteQueryBuilder = $this->createStub(\Doctrine\DBAL\Query\QueryBuilder::class);
		$queryBuilder->method('getConcreteQueryBuilder')->willReturn($concreteQueryBuilder);

		$expressionBuilder->expects($this->atLeastOnce())
			->method('eq')
			->with('header', $this->anything())
			->willReturn('header = :ptr');

		$queryBuilder->expects($this->atLeastOnce())
			->method('andWhere')
			->with('header = :ptr')
			->willReturn($queryBuilder);

		$result = $this->createStub(Result::class);
		$queryBuilder->method('executeQuery')->willReturn($result);
		$this->paginationService->method('getPaginationParams')->willReturn(['offset' => 0, 'limit' => 10]);

		$this->controller->listAction($request);
	}

	public function testListActionFiltersWithString(): void {
		$GLOBALS['TCA']['tt_content']['columns']['header'] = [];
		$request = $this->createStub(ServerRequestInterface::class);
		$resourceConfig = [
			'table' => 'tt_content',
			'readFields' => ['header']
		];
		$request->method('getAttribute')->with('api.resource')->willReturn($resourceConfig);
		// filter[header]=Test
		$request->method('getQueryParams')->willReturn(['filter' => 'filter[header]=Test']);

		$queryBuilder = $this->createMock(QueryBuilder::class);
		$this->connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);

		$expressionBuilder = $this->createMock(ExpressionBuilder::class);
		$queryBuilder->method('expr')->willReturn($expressionBuilder);
		$queryBuilder->method('select')->willReturn($queryBuilder);
		$queryBuilder->method('from')->willReturn($queryBuilder);
		$queryBuilder->method('setFirstResult')->willReturn($queryBuilder);
		$queryBuilder->method('setMaxResults')->willReturn($queryBuilder);
		$queryBuilder->method('andWhere')->willReturn($queryBuilder);
		$concreteQueryBuilder = $this->createStub(\Doctrine\DBAL\Query\QueryBuilder::class);
		$queryBuilder->method('getConcreteQueryBuilder')->willReturn($concreteQueryBuilder);

		$expressionBuilder->expects($this->atLeastOnce())
			->method('eq')
			->with('header', $this->anything())
			->willReturn('header = :ptr');

		$queryBuilder->expects($this->atLeastOnce())
			->method('andWhere')
			->with('header = :ptr')
			->willReturn($queryBuilder);

		$result = $this->createStub(Result::class);
		$queryBuilder->method('executeQuery')->willReturn($result);
		$this->paginationService->method('getPaginationParams')->willReturn(['offset' => 0, 'limit' => 10]);

		$this->controller->listAction($request);
	}

	public function testGetActionReturns404IfNotFound(): void {
		$request = $this->createStub(ServerRequestInterface::class);
		$resourceConfig = [
			'table' => 'tt_content',
			'idField' => 'uid',
			'readFields' => []
		];
		$request->method('getAttribute')->with('api.resource')->willReturn($resourceConfig);

		$queryBuilder = $this->createStub(QueryBuilder::class);
		$this->connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);
		$queryBuilder->method('select')->willReturn($queryBuilder);
		$queryBuilder->method('from')->willReturn($queryBuilder);
		$queryBuilder->method('where')->willReturn($queryBuilder);
		$queryBuilder->method('expr')->willReturn($this->createStub(ExpressionBuilder::class));

		$result = $this->createStub(Result::class);
		$queryBuilder->method('executeQuery')->willReturn($result);
		$result->method('fetchAssociative')->willReturn(FALSE);

		$this->responseService->method('createErrorResponse')->willReturnCallback(
			fn ($title, $detail, $status) => new JsonResponse(['title' => $title], $status)
		);

		$response = $this->controller->getAction($request, '999');

		$this->assertEquals(404, $response->getStatusCode());
	}

	public function testCreateActionUsesProvidedPid(): void {
		$request = $this->createStub(ServerRequestInterface::class);
		$resourceConfig = [
			'table' => 'tt_content',
			'writeFields' => ['header']
		];
		$request->method('getAttribute')->willReturnCallback(function ($name) use ($resourceConfig) {
			if ($name === 'api.resource') {
				return $resourceConfig;
			}
			return NULL;
		});
		$request->method('getParsedBody')->willReturn(['header' => 'New', 'pid' => 123]);

		$this->tcaMapper->method('mapDataForDatabase')->willReturn(['header' => 'New']);

		$dataHandler = $this->createMock(DataHandler::class);
		GeneralUtility::addInstance(DataHandler::class, $dataHandler);

		$dataHandler->expects($this->once())
			->method('start')
			->with(
				$this->callback(function ($dataMap) {
					return isset($dataMap['tt_content']['NEW1']['pid']) && $dataMap['tt_content']['NEW1']['pid'] === 123;
				}),
				[]
			);

		$this->responseService->method('createSuccessResponse')->willReturn(new JsonResponse([]));

		$this->controller->createAction($request);
	}

	public function testCreateActionUsesTenantRootPageIdIfPidMissing(): void {
		$request = $this->createStub(ServerRequestInterface::class);
		$resourceConfig = [
			'table' => 'tt_content',
			'writeFields' => ['header']
		];

		$site = $this->createStub(Site::class);
		$site->method('getRootPageId')->willReturn(456);
		$tenantContext = new TenantContext('test-tenant', NULL, NULL, $site);

		$request->method('getAttribute')->willReturnCallback(function ($name) use ($resourceConfig, $tenantContext) {
			if ($name === 'api.resource') {
				return $resourceConfig;
			}
			if ($name === 'api.tenant') {
				return $tenantContext;
			}
			return NULL;
		});
		$request->method('getParsedBody')->willReturn(['header' => 'New']);

		$this->tcaMapper->method('mapDataForDatabase')->willReturn(['header' => 'New']);

		$dataHandler = $this->createMock(DataHandler::class);
		GeneralUtility::addInstance(DataHandler::class, $dataHandler);

		$dataHandler->expects($this->once())
			->method('start')
			->with(
				$this->callback(function ($dataMap) {
					return isset($dataMap['tt_content']['NEW1']['pid']) && $dataMap['tt_content']['NEW1']['pid'] === 456;
				}),
				[]
			);

		$this->responseService->method('createSuccessResponse')->willReturn(new JsonResponse([]));

		$this->controller->createAction($request);
	}

	public function testUpdateActionUpdatesCorrectRecord(): void {
		$request = $this->createStub(ServerRequestInterface::class);
		$resourceConfig = [
			'table' => 'tt_content',
			'idField' => 'uid',
			'readFields' => ['header'],
			'writeFields' => ['header']
		];
		$request->method('getAttribute')->with('api.resource')->willReturn($resourceConfig);
		$request->method('getParsedBody')->willReturn(['header' => 'Updated Header']);

		$queryBuilder = $this->createStub(QueryBuilder::class);
		$this->connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);
		$queryBuilder->method('select')->willReturn($queryBuilder);
		$queryBuilder->method('from')->willReturn($queryBuilder);
		$queryBuilder->method('where')->willReturn($queryBuilder);
		$queryBuilder->method('expr')->willReturn($this->createStub(ExpressionBuilder::class));

		$result = $this->createStub(Result::class);
		$queryBuilder->method('executeQuery')->willReturn($result);
		$result->method('fetchOne')->willReturn(4471);

		$this->tcaMapper->method('mapDataForDatabase')->willReturn(['header' => 'Updated Header']);

		$dataHandler = $this->createMock(DataHandler::class);
		GeneralUtility::addInstance(DataHandler::class, $dataHandler);

		$dataHandler->expects($this->once())
			->method('start')
			->with(
				$this->callback(function ($dataMap) {
					return isset($dataMap['tt_content'][4471]['header']) && $dataMap['tt_content'][4471]['header'] === 'Updated Header';
				}),
				[]
			);

		// Since updateAction calls getAction, we need to mock that too or its dependencies
		$result->method('fetchAssociative')->willReturn(['uid' => 4471, 'header' => 'Updated Header']);
		$this->tcaMapper->method('mapRecord')->with(
			$this->equalTo('tt_content'),
			$this->anything(),
			$this->equalTo(['header'])
		)->willReturn(['uid' => 4471, 'header' => 'Updated Header']);
		$this->responseService->method('createSuccessResponse')->willReturnCallback(
			fn ($data) => new JsonResponse($data)
		);

		$response = $this->controller->updateAction($request, '4471');

		$this->assertInstanceOf(JsonResponse::class, $response);
		$this->assertEquals(200, $response->getStatusCode());
		$data = json_decode((string) $response->getBody(), TRUE);
		$this->assertEquals('Updated Header', $data['header']);
	}
}
