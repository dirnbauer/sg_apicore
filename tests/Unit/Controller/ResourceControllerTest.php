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

namespace SGalinski\SgApiCore\Tests\Unit\Controller;

use Doctrine\DBAL\Result;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Http\Message\ServerRequestInterface;
use SGalinski\SgApiCore\Configuration\ExtensionConfiguration;
use SGalinski\SgApiCore\Context\TenantContext;
use SGalinski\SgApiCore\Controller\ResourceController;
use SGalinski\SgApiCore\Mapper\TcaMapper;
use SGalinski\SgApiCore\Service\LogService;
use SGalinski\SgApiCore\Service\PaginationService;
use SGalinski\SgApiCore\Service\ResponseService;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\QueryRestrictionContainerInterface;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
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
	protected LogService|MockObject $logService;
	protected ExtensionConfiguration|MockObject $extensionConfiguration;
	protected LanguageServiceFactory|MockObject $languageServiceFactory;
	protected Typo3Version|MockObject $typo3Version;

	protected function setUp(): void {
		parent::setUp();
		$this->resetSingletonInstances = TRUE;
		$this->connectionPool = $this->createStub(ConnectionPool::class);
		$this->tcaMapper = $this->createStub(TcaMapper::class);
		$this->responseService = $this->createStub(ResponseService::class);
		$this->paginationService = $this->createStub(PaginationService::class);
		$this->extensionConfiguration = $this->createStub(ExtensionConfiguration::class);
		$this->extensionConfiguration->method('getApiResourceWriteBackendUserId')->willReturn(0);
		$this->languageServiceFactory = $this->createStub(LanguageServiceFactory::class);
		$this->languageServiceFactory->method('create')->willReturn($this->createStub(LanguageService::class));
		$this->typo3Version = $this->createStub(Typo3Version::class);

		// Mock LogManager to avoid singleton issues in tests
		$logManager = $this->createStub(LogManager::class);
		GeneralUtility::setSingletonInstance(LogManager::class, $logManager);

		// Mock BE_USER
		$GLOBALS['BE_USER'] = $this->createStub(BackendUserAuthentication::class);

		$this->logService = $this->createStub(LogService::class);

		$this->controller = new ResourceController(
			$this->connectionPool,
			$this->tcaMapper,
			$this->responseService,
			$this->paginationService,
			$this->logService,
			$this->extensionConfiguration,
			$this->languageServiceFactory,
			$this->typo3Version
		);
	}

	protected function tearDown(): void {
		unset($GLOBALS['BE_USER']);
		unset($GLOBALS['LANG']);
		unset($GLOBALS['TCA']['tt_content']);
		parent::tearDown();
	}

	public function testListActionReturnsMappedRecords(): void {
		$GLOBALS['TCA']['tt_content']['columns']['header'] = [];
		$request = $this->createStub(ServerRequestInterface::class);
		$resourceConfig = [
			'table' => 'tt_content',
			'readFields' => ['header'],
		];
		$request->method('getAttribute')->willReturnCallback(static function ($name) use ($resourceConfig) {
			if ($name === 'api.resource') {
				return $resourceConfig;
			}
			return NULL;
		});
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
			'readFields' => ['header'],
		];
		$request->method('getAttribute')->willReturnCallback(static function ($name) use ($resourceConfig) {
			if ($name === 'api.resource') {
				return $resourceConfig;
			}
			return NULL;
		});
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
			'readFields' => ['header'],
		];
		$request->method('getAttribute')->willReturnCallback(static function ($name) use ($resourceConfig) {
			if ($name === 'api.resource') {
				return $resourceConfig;
			}
			return NULL;
		});
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
			'readFields' => [],
		];
		$request->method('getAttribute')->willReturnCallback(static function ($name) use ($resourceConfig) {
			if ($name === 'api.resource') {
				return $resourceConfig;
			}
			return NULL;
		});

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

	public function testListActionUsesDeletedOnlyRestrictionsForReads(): void {
		$request = $this->createStub(ServerRequestInterface::class);
		$resourceConfig = [
			'table' => 'pages',
			'readFields' => ['title'],
		];
		$request->method('getAttribute')->willReturnCallback(static function ($name) use ($resourceConfig) {
			if ($name === 'api.resource') {
				return $resourceConfig;
			}
			if ($name === 'api.executionContext') {
				return 'mcp';
			}
			return NULL;
		});
		$request->method('getQueryParams')->willReturn([]);

		$queryBuilder = $this->createStub(QueryBuilder::class);
		$restrictions = $this->createMock(QueryRestrictionContainerInterface::class);
		$result = $this->createStub(Result::class);
		$concreteQueryBuilder = $this->createStub(\Doctrine\DBAL\Query\QueryBuilder::class);

		$this->connectionPool->method('getQueryBuilderForTable')->with('pages')->willReturn($queryBuilder);
		$queryBuilder->method('getRestrictions')->willReturn($restrictions);
		$queryBuilder->method('select')->willReturn($queryBuilder);
		$queryBuilder->method('from')->willReturn($queryBuilder);
		$queryBuilder->method('setFirstResult')->willReturn($queryBuilder);
		$queryBuilder->method('setMaxResults')->willReturn($queryBuilder);
		$queryBuilder->method('count')->willReturn($queryBuilder);
		$queryBuilder->method('getConcreteQueryBuilder')->willReturn($concreteQueryBuilder);
		$queryBuilder->method('executeQuery')->willReturn($result);
		$result->method('fetchAllAssociative')->willReturn([]);

		$restrictions->expects($this->once())->method('removeAll');
		$restrictions->expects($this->once())->method('add')->with($this->isInstanceOf(DeletedRestriction::class));

		$this->paginationService->method('getPaginationParams')->willReturn(['offset' => 0, 'limit' => 10]);
		$this->tcaMapper->method('mapRecords')->willReturn([]);
		$this->responseService->method('createSuccessResponse')->willReturn(new JsonResponse([]));

		$this->controller->listAction($request);
	}

	public function testListActionKeepsDefaultRestrictionsOutsideMcpContext(): void {
		$request = $this->createStub(ServerRequestInterface::class);
		$resourceConfig = [
			'table' => 'pages',
			'readFields' => ['title'],
		];
		$request->method('getAttribute')->willReturnCallback(static function ($name) use ($resourceConfig) {
			if ($name === 'api.resource') {
				return $resourceConfig;
			}
			return NULL;
		});
		$request->method('getQueryParams')->willReturn([]);

		$queryBuilder = $this->createMock(QueryBuilder::class);
		$result = $this->createStub(Result::class);
		$concreteQueryBuilder = $this->createStub(\Doctrine\DBAL\Query\QueryBuilder::class);

		$this->connectionPool->method('getQueryBuilderForTable')->with('pages')->willReturn($queryBuilder);
		$queryBuilder->expects($this->never())->method('getRestrictions');
		$queryBuilder->method('select')->willReturn($queryBuilder);
		$queryBuilder->method('from')->willReturn($queryBuilder);
		$queryBuilder->method('setFirstResult')->willReturn($queryBuilder);
		$queryBuilder->method('setMaxResults')->willReturn($queryBuilder);
		$queryBuilder->method('count')->willReturn($queryBuilder);
		$queryBuilder->method('getConcreteQueryBuilder')->willReturn($concreteQueryBuilder);
		$queryBuilder->method('executeQuery')->willReturn($result);
		$result->method('fetchAllAssociative')->willReturn([]);

		$this->paginationService->method('getPaginationParams')->willReturn(['offset' => 0, 'limit' => 10]);
		$this->tcaMapper->method('mapRecords')->willReturn([]);
		$this->responseService->method('createSuccessResponse')->willReturn(new JsonResponse([]));

		$this->controller->listAction($request);
	}

	public function testGetActionUsesDeletedOnlyRestrictionsForReads(): void {
		$request = $this->createStub(ServerRequestInterface::class);
		$resourceConfig = [
			'table' => 'pages',
			'idField' => 'uid',
			'readFields' => ['title'],
		];
		$request->method('getAttribute')->willReturnCallback(static function ($name) use ($resourceConfig) {
			if ($name === 'api.resource') {
				return $resourceConfig;
			}
			if ($name === 'api.executionContext') {
				return 'mcp';
			}
			return NULL;
		});

		$queryBuilder = $this->createStub(QueryBuilder::class);
		$restrictions = $this->createMock(QueryRestrictionContainerInterface::class);
		$expressionBuilder = $this->createStub(ExpressionBuilder::class);
		$result = $this->createStub(Result::class);

		$this->connectionPool->method('getQueryBuilderForTable')->with('pages')->willReturn($queryBuilder);
		$queryBuilder->method('getRestrictions')->willReturn($restrictions);
		$queryBuilder->method('select')->willReturn($queryBuilder);
		$queryBuilder->method('from')->willReturn($queryBuilder);
		$queryBuilder->method('where')->willReturn($queryBuilder);
		$queryBuilder->method('expr')->willReturn($expressionBuilder);
		$queryBuilder->method('executeQuery')->willReturn($result);
		$result->method('fetchAssociative')->willReturn(['uid' => 677, 'title' => 'Hidden page']);
		$this->tcaMapper->method('mapRecord')->willReturn(['uid' => 677, 'title' => 'Hidden page']);
		$this->responseService->method('createSuccessResponse')->willReturn(new JsonResponse(['uid' => 677]));

		$restrictions->expects($this->once())->method('removeAll');
		$restrictions->expects($this->once())->method('add')->with($this->isInstanceOf(DeletedRestriction::class));

		$response = $this->controller->getAction($request, '677');

		$this->assertSame(200, $response->getStatusCode());
	}

	public function testCreateActionUsesProvidedPid(): void {
		$request = $this->createStub(ServerRequestInterface::class);
		$resourceConfig = [
			'table' => 'tt_content',
			'readFields' => ['header'],
			'writeFields' => ['header'],
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
		$dataHandler->errorLog = [];
		$dataHandler->substNEWwithIDs = ['NEW1' => 777];
		GeneralUtility::addInstance(DataHandler::class, $dataHandler);

		$dataHandler->expects($this->once())
			->method('start')
			->with(
				$this->callback(function ($dataMap) {
					return isset($dataMap['tt_content']['NEW1']['pid']) && $dataMap['tt_content']['NEW1']['pid'] === 123;
				}),
				[]
			);

		$queryBuilder = $this->createStub(QueryBuilder::class);
		$this->connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);
		$queryBuilder->method('select')->willReturn($queryBuilder);
		$queryBuilder->method('from')->willReturn($queryBuilder);
		$queryBuilder->method('where')->willReturn($queryBuilder);
		$queryBuilder->method('expr')->willReturn($this->createStub(ExpressionBuilder::class));
		$restrictions = $this->createStub(QueryRestrictionContainerInterface::class);
		$restrictions->method('removeAll')->willReturn($restrictions);
		$restrictions->method('add')->willReturn($restrictions);
		$queryBuilder->method('getRestrictions')->willReturn($restrictions);

		$result = $this->createStub(Result::class);
		$queryBuilder->method('executeQuery')->willReturn($result);
		$result->method('fetchAssociative')->willReturn(['uid' => 777, 'header' => 'New']);

		$this->tcaMapper->method('mapRecord')->willReturn(['uid' => 777, 'header' => 'New']);
		$this->responseService->method('createSuccessResponse')->willReturn(new JsonResponse(['uid' => 777]));

		$this->controller->createAction($request);
	}

	public function testCreateActionUsesTenantRootPageIdIfPidMissing(): void {
		$request = $this->createStub(ServerRequestInterface::class);
		$resourceConfig = [
			'table' => 'tt_content',
			'readFields' => ['header'],
			'writeFields' => ['header'],
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
		$dataHandler->errorLog = [];
		$dataHandler->substNEWwithIDs = ['NEW1' => 888];
		GeneralUtility::addInstance(DataHandler::class, $dataHandler);

		$dataHandler->expects($this->once())
			->method('start')
			->with(
				$this->callback(function ($dataMap) {
					return isset($dataMap['tt_content']['NEW1']['pid']) && $dataMap['tt_content']['NEW1']['pid'] === 456;
				}),
				[]
			);

		$queryBuilder = $this->createStub(QueryBuilder::class);
		$this->connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);
		$queryBuilder->method('select')->willReturn($queryBuilder);
		$queryBuilder->method('from')->willReturn($queryBuilder);
		$queryBuilder->method('where')->willReturn($queryBuilder);
		$queryBuilder->method('expr')->willReturn($this->createStub(ExpressionBuilder::class));
		$restrictions = $this->createStub(QueryRestrictionContainerInterface::class);
		$restrictions->method('removeAll')->willReturn($restrictions);
		$restrictions->method('add')->willReturn($restrictions);
		$queryBuilder->method('getRestrictions')->willReturn($restrictions);

		$result = $this->createStub(Result::class);
		$queryBuilder->method('executeQuery')->willReturn($result);
		$result->method('fetchAssociative')->willReturn(['uid' => 888, 'header' => 'New']);

		$this->tcaMapper->method('mapRecord')->willReturn(['uid' => 888, 'header' => 'New']);
		$this->responseService->method('createSuccessResponse')->willReturn(new JsonResponse(['uid' => 888]));

		$this->controller->createAction($request);
	}

	public function testCreateActionFetchesCreatedRecordByUidInsteadOfConfiguredIdField(): void {
		$request = $this->createStub(ServerRequestInterface::class);
		$resourceConfig = [
			'table' => 'pages',
			'idField' => 'slug',
			'readFields' => ['title', 'slug'],
			'writeFields' => ['title', 'slug'],
		];
		$request->method('getAttribute')->willReturnCallback(function ($name) use ($resourceConfig) {
			if ($name === 'api.resource') {
				return $resourceConfig;
			}
			return NULL;
		});
		$request->method('getParsedBody')->willReturn([
			'title' => 'New page',
			'slug' => '/new-page',
			'pid' => 1,
		]);

		$this->tcaMapper->method('mapDataForDatabase')->willReturn([
			'title' => 'New page',
			'slug' => '/new-page',
		]);

		$dataHandler = $this->createMock(DataHandler::class);
		$dataHandler->errorLog = [];
		$dataHandler->substNEWwithIDs = ['NEW1' => 999];
		GeneralUtility::addInstance(DataHandler::class, $dataHandler);

		$queryBuilder = $this->createMock(QueryBuilder::class);
		$expressionBuilder = $this->createMock(ExpressionBuilder::class);
		$restrictions = $this->createStub(QueryRestrictionContainerInterface::class);
		$restrictions->method('removeAll')->willReturn($restrictions);
		$restrictions->method('add')->willReturn($restrictions);

		$this->connectionPool->method('getQueryBuilderForTable')->with('pages')->willReturn($queryBuilder);
		$queryBuilder->method('getRestrictions')->willReturn($restrictions);
		$queryBuilder->method('select')->with('*')->willReturn($queryBuilder);
		$queryBuilder->method('from')->with('pages')->willReturn($queryBuilder);
		$queryBuilder->method('where')->with('uid = :uid')->willReturn($queryBuilder);
		$queryBuilder->method('expr')->willReturn($expressionBuilder);
		$queryBuilder->method('createNamedParameter')->with(999)->willReturn(':uid');
		$expressionBuilder->expects($this->once())
			->method('eq')
			->with('uid', ':uid')
			->willReturn('uid = :uid');

		$result = $this->createStub(Result::class);
		$queryBuilder->method('executeQuery')->willReturn($result);
		$result->method('fetchAssociative')->willReturn([
			'uid' => 999,
			'title' => 'New page',
			'slug' => '/new-page',
		]);

		$this->tcaMapper->method('mapRecord')->with(
			'pages',
			['uid' => 999, 'title' => 'New page', 'slug' => '/new-page'],
			['title', 'slug'],
			$this->anything(),
			1,
			[]
		)->willReturn([
			'uid' => 999,
			'title' => 'New page',
			'slug' => '/new-page',
		]);

		$responseService = $this->createMock(ResponseService::class);
		$responseService->expects($this->once())
			->method('createSuccessResponse')
			->with(['uid' => 999, 'title' => 'New page', 'slug' => '/new-page'])
			->willReturn(new JsonResponse(['uid' => 999], 200));

		$controller = new ResourceController(
			$this->connectionPool,
			$this->tcaMapper,
			$responseService,
			$this->paginationService,
			$this->logService,
			$this->extensionConfiguration,
			$this->languageServiceFactory,
			$this->typo3Version
		);

		$response = $controller->createAction($request);

		$this->assertSame(200, $response->getStatusCode());
	}

	public function testCreateActionPlacesTtContentAtBottomWhenPositionBottomIsRequested(): void {
		$request = $this->createStub(ServerRequestInterface::class);
		$resourceConfig = [
			'table' => 'tt_content',
			'readFields' => ['header', 'pid', 'colPos'],
			'writeFields' => ['header', 'pid', 'colPos'],
		];
		$request->method('getAttribute')->willReturnCallback(function ($name) use ($resourceConfig) {
			if ($name === 'api.resource') {
				return $resourceConfig;
			}
			return NULL;
		});
		$request->method('getParsedBody')->willReturn([
			'header' => 'Bottom element',
			'pid' => 55,
			'colPos' => 2,
			'position' => 'bottom',
		]);

		$this->tcaMapper->method('mapDataForDatabase')->willReturn([
			'header' => 'Bottom element',
			'pid' => 55,
			'colPos' => 2,
		]);

		$dataHandler = $this->createMock(DataHandler::class);
		$dataHandler->errorLog = [];
		$dataHandler->substNEWwithIDs = ['NEW1' => 901];
		GeneralUtility::addInstance(DataHandler::class, $dataHandler);

		$queryBuilder = $this->createMock(QueryBuilder::class);
		$expressionBuilder = $this->createStub(ExpressionBuilder::class);
		$expressionBuilder->method('eq')->willReturn('expr');
		$restrictions = $this->createStub(QueryRestrictionContainerInterface::class);
		$restrictions->method('removeAll')->willReturn($restrictions);
		$restrictions->method('add')->willReturn($restrictions);

		$this->connectionPool->method('getQueryBuilderForTable')->willReturnCallback(function ($table) use ($queryBuilder) {
			return $queryBuilder;
		});
		$queryBuilder->method('getRestrictions')->willReturn($restrictions);
		$queryBuilder->method('select')->willReturn($queryBuilder);
		$queryBuilder->method('from')->willReturn($queryBuilder);
		$queryBuilder->method('where')->willReturn($queryBuilder);
		$queryBuilder->method('andWhere')->willReturn($queryBuilder);
		$queryBuilder->method('orderBy')->willReturn($queryBuilder);
		$queryBuilder->method('addOrderBy')->willReturn($queryBuilder);
		$queryBuilder->method('setMaxResults')->willReturn($queryBuilder);
		$queryBuilder->method('expr')->willReturn($expressionBuilder);
		$queryBuilder->method('createNamedParameter')->willReturn(':param');

		$result = $this->createMock(Result::class);
		$queryBuilder->method('executeQuery')->willReturn($result);
		$result->method('fetchOne')->willReturn(777);
		$result->method('fetchAssociative')->willReturn(['uid' => 901, 'header' => 'Bottom element', 'pid' => 55]);

		$dataHandler->expects($this->once())
			->method('start')
			->with(
				$this->callback(function ($dataMap) {
					return isset($dataMap['tt_content']['NEW1']['pid']) && $dataMap['tt_content']['NEW1']['pid'] === -777;
				}),
				[]
			);

		$this->tcaMapper->method('mapRecord')->willReturn(['uid' => 901, 'header' => 'Bottom element']);
		$this->responseService->method('createSuccessResponse')->willReturn(new JsonResponse(['uid' => 901], 200));

		$response = $this->controller->createAction($request);

		$this->assertSame(200, $response->getStatusCode());
	}

	public function testCreateActionPlacesTtContentAfterReferencedRecordWhenAfterUidIsProvided(): void {
		$request = $this->createStub(ServerRequestInterface::class);
		$resourceConfig = [
			'table' => 'tt_content',
			'readFields' => ['header', 'pid', 'colPos', 'sys_language_uid'],
			'writeFields' => ['header'],
		];
		$request->method('getAttribute')->willReturnCallback(function ($name) use ($resourceConfig) {
			if ($name === 'api.resource') {
				return $resourceConfig;
			}
			return NULL;
		});
		$request->method('getParsedBody')->willReturn([
			'header' => 'After element',
			'position' => 'after',
			'afterUid' => 321,
		]);

		$this->tcaMapper->method('mapDataForDatabase')->willReturn([
			'header' => 'After element',
		]);

		$dataHandler = $this->createMock(DataHandler::class);
		$dataHandler->errorLog = [];
		$dataHandler->substNEWwithIDs = ['NEW1' => 902];
		GeneralUtility::addInstance(DataHandler::class, $dataHandler);

		$queryBuilder = $this->createMock(QueryBuilder::class);
		$expressionBuilder = $this->createStub(ExpressionBuilder::class);
		$expressionBuilder->method('eq')->willReturn('expr');
		$restrictions = $this->createStub(QueryRestrictionContainerInterface::class);
		$restrictions->method('removeAll')->willReturn($restrictions);
		$restrictions->method('add')->willReturn($restrictions);

		$this->connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);
		$queryBuilder->method('getRestrictions')->willReturn($restrictions);
		$queryBuilder->method('select')->willReturn($queryBuilder);
		$queryBuilder->method('from')->willReturn($queryBuilder);
		$queryBuilder->method('where')->willReturn($queryBuilder);
		$queryBuilder->method('setMaxResults')->willReturn($queryBuilder);
		$queryBuilder->method('expr')->willReturn($expressionBuilder);
		$queryBuilder->method('createNamedParameter')->willReturn(':param');

		$result = $this->createMock(Result::class);
		$queryBuilder->method('executeQuery')->willReturn($result);
		$result->method('fetchAssociative')->willReturnOnConsecutiveCalls(
			['uid' => 321, 'pid' => 77, 'colPos' => 3, 'sys_language_uid' => 1],
			['uid' => 902, 'header' => 'After element', 'pid' => 77, 'colPos' => 3, 'sys_language_uid' => 1]
		);

		$dataHandler->expects($this->once())
			->method('start')
			->with(
				$this->callback(function ($dataMap) {
					$record = $dataMap['tt_content']['NEW1'] ?? [];
					return ($record['pid'] ?? 0) === -321
						&& ($record['colPos'] ?? 0) === 3
						&& ($record['sys_language_uid'] ?? 0) === 1;
				}),
				[]
			);

		$this->tcaMapper->method('mapRecord')->willReturn(['uid' => 902, 'header' => 'After element']);
		$this->responseService->method('createSuccessResponse')->willReturn(new JsonResponse(['uid' => 902], 200));

		$response = $this->controller->createAction($request);

		$this->assertSame(200, $response->getStatusCode());
	}

	public function testCreateActionReturnsErrorWhenDataHandlerDoesNotResolveNewRecordUid(): void {
		$request = $this->createStub(ServerRequestInterface::class);
		$resourceConfig = [
			'table' => 'pages',
			'writeFields' => ['title'],
		];
		$request->method('getAttribute')->willReturnCallback(function ($name) use ($resourceConfig) {
			if ($name === 'api.resource') {
				return $resourceConfig;
			}
			return NULL;
		});
		$request->method('getParsedBody')->willReturn(['title' => 'New page', 'pid' => 1]);

		$this->tcaMapper->method('mapDataForDatabase')->willReturn(['title' => 'New page']);

		$dataHandler = $this->createMock(DataHandler::class);
		$dataHandler->errorLog = [];
		$dataHandler->substNEWwithIDs = [];
		GeneralUtility::addInstance(DataHandler::class, $dataHandler);

		$responseService = $this->createMock(ResponseService::class);
		$responseService->expects($this->once())
			->method('createErrorResponse')
			->with('Internal Error', 'The record could not be created successfully.', 500)
			->willReturn(new JsonResponse(['error' => TRUE], 500));

		$controller = new ResourceController(
			$this->connectionPool,
			$this->tcaMapper,
			$responseService,
			$this->paginationService,
			$this->logService,
			$this->extensionConfiguration,
			$this->languageServiceFactory,
			$this->typo3Version
		);

		$response = $controller->createAction($request);

		$this->assertInstanceOf(JsonResponse::class, $response);
		$this->assertSame(500, $response->getStatusCode());
	}

	public function testUpdateActionUpdatesCorrectRecord(): void {
		$request = $this->createStub(ServerRequestInterface::class);
		$resourceConfig = [
			'table' => 'tt_content',
			'idField' => 'uid',
			'readFields' => ['header'],
			'writeFields' => ['header'],
		];
		$request->method('getAttribute')->willReturnCallback(static function ($name) use ($resourceConfig) {
			if ($name === 'api.resource') {
				return $resourceConfig;
			}
			if ($name === 'api.executionContext') {
				return 'mcp';
			}
			return NULL;
		});
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

	public function testUpdateActionUsesDeletedOnlyRestrictionsForExistenceCheck(): void {
		$request = $this->createStub(ServerRequestInterface::class);
		$resourceConfig = [
			'table' => 'pages',
			'idField' => 'uid',
			'readFields' => ['title'],
			'writeFields' => ['title'],
		];
		$request->method('getAttribute')->willReturnCallback(static function ($name) use ($resourceConfig) {
			if ($name === 'api.resource') {
				return $resourceConfig;
			}
			if ($name === 'api.executionContext') {
				return 'mcp';
			}
			return NULL;
		});
		$request->method('getParsedBody')->willReturn([]);

		$queryBuilder = $this->createStub(QueryBuilder::class);
		$restrictions = $this->createMock(QueryRestrictionContainerInterface::class);
		$result = $this->createStub(Result::class);
		$expressionBuilder = $this->createStub(ExpressionBuilder::class);

		$this->connectionPool->method('getQueryBuilderForTable')->with('pages')->willReturn($queryBuilder);
		$queryBuilder->method('getRestrictions')->willReturn($restrictions);
		$queryBuilder->method('select')->willReturn($queryBuilder);
		$queryBuilder->method('from')->willReturn($queryBuilder);
		$queryBuilder->method('where')->willReturn($queryBuilder);
		$queryBuilder->method('expr')->willReturn($expressionBuilder);
		$queryBuilder->method('executeQuery')->willReturn($result);
		$result->method('fetchOne')->willReturn(677);
		$result->method('fetchAssociative')->willReturn(['uid' => 677, 'title' => 'AIO']);

		$restrictions->expects($this->exactly(2))->method('removeAll');
		$restrictions->expects($this->exactly(2))
			->method('add')
			->with($this->isInstanceOf(DeletedRestriction::class));

		$this->tcaMapper->method('mapDataForDatabase')->willReturn([]);
		$this->tcaMapper->method('mapRecord')->willReturn(['uid' => 677, 'title' => 'AIO']);
		$this->responseService->method('createSuccessResponse')->willReturnCallback(
			fn ($data) => new JsonResponse($data)
		);

		$response = $this->controller->updateAction($request, '677');

		$this->assertSame(200, $response->getStatusCode());
	}

	public function testCreateActionForcesLiveWorkspaceOnExistingBackendUser(): void {
		$GLOBALS['TCA']['pages']['columns']['title'] = [];
		$backendUser = new BackendUserAuthentication();
		$backendUser->workspace = 99;
		$backendUser->workspaceRec = ['uid' => 99];
		$backendUser->user = ['workspace_id' => 99];
		$GLOBALS['BE_USER'] = $backendUser;

		$request = $this->createStub(ServerRequestInterface::class);
		$resourceConfig = [
			'table' => 'pages',
			'readFields' => ['title'],
			'writeFields' => ['title'],
		];
		$request->method('getAttribute')->willReturnCallback(function ($name) use ($resourceConfig) {
			if ($name === 'api.resource') {
				return $resourceConfig;
			}
			return NULL;
		});
		$request->method('getParsedBody')->willReturn([
			'title' => 'Workspace reset',
			'pid' => 1,
		]);

		$this->tcaMapper->method('mapDataForDatabase')->willReturn([
			'title' => 'Workspace reset',
		]);

		$dataHandler = $this->createMock(DataHandler::class);
		$dataHandler->errorLog = [];
		$dataHandler->substNEWwithIDs = ['NEW1' => 321];
		GeneralUtility::addInstance(DataHandler::class, $dataHandler);

		$queryBuilder = $this->createStub(QueryBuilder::class);
		$restrictions = $this->createStub(QueryRestrictionContainerInterface::class);
		$expressionBuilder = $this->createStub(ExpressionBuilder::class);
		$result = $this->createStub(Result::class);

		$this->connectionPool->method('getQueryBuilderForTable')->with('pages')->willReturn($queryBuilder);
		$restrictions->method('removeAll')->willReturn($restrictions);
		$restrictions->method('add')->willReturn($restrictions);
		$queryBuilder->method('getRestrictions')->willReturn($restrictions);
		$queryBuilder->method('select')->willReturn($queryBuilder);
		$queryBuilder->method('from')->willReturn($queryBuilder);
		$queryBuilder->method('where')->willReturn($queryBuilder);
		$queryBuilder->method('expr')->willReturn($expressionBuilder);
		$queryBuilder->method('executeQuery')->willReturn($result);
		$result->method('fetchAssociative')->willReturn(['uid' => 321, 'title' => 'Workspace reset']);

		$this->tcaMapper->method('mapRecord')->willReturn(['uid' => 321, 'title' => 'Workspace reset']);
		$this->responseService->method('createSuccessResponse')->willReturn(new JsonResponse(['uid' => 321], 200));

		$response = $this->controller->createAction($request);

		$this->assertSame(200, $response->getStatusCode());
		$this->assertSame(0, $backendUser->workspace);
		$this->assertSame([], $backendUser->workspaceRec);
		$this->assertSame(0, $backendUser->user['workspace_id']);
	}
}
