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
use SGalinski\SgApiCore\Controller\ResourceController;
use SGalinski\SgApiCore\Mapper\TcaMapper;
use SGalinski\SgApiCore\Service\PaginationService;
use SGalinski\SgApiCore\Service\ResponseService;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Http\JsonResponse;
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

	protected function setUp(): void {
		parent::setUp();
		$this->connectionPool = $this->createStub(ConnectionPool::class);
		$this->tcaMapper = $this->createStub(TcaMapper::class);
		$this->responseService = $this->createStub(ResponseService::class);
		$this->paginationService = $this->createStub(PaginationService::class);

		$this->controller = new ResourceController(
			$this->connectionPool,
			$this->tcaMapper,
			$this->responseService,
			$this->paginationService
		);
	}

	public function testListActionReturnsMappedRecords(): void {
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
		$queryBuilder->method('resetOrderBy')->willReturn($queryBuilder);
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
}
