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

namespace SGalinski\SgApiCore\Tests\Unit\Service;

use Psr\Http\Message\ServerRequestInterface;
use SGalinski\SgApiCore\Service\PaginationService;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Test case for PaginationService
 */
class PaginationServiceTest extends UnitTestCase {
	/**
	 * @var PaginationService
	 */
	protected PaginationService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->service = new PaginationService();
	}

	public function testGetPaginationParamsReturnsDefaults(): void {
		$request = $this->createStub(ServerRequestInterface::class);
		$request->method('getQueryParams')->willReturn([]);

		$params = $this->service->getPaginationParams($request);

		$this->assertEquals(0, $params['offset']);
		$this->assertEquals(PaginationService::DEFAULT_LIMIT, $params['limit']);
	}

	public function testGetPaginationParamsReturnsProvidedValues(): void {
		$request = $this->createStub(ServerRequestInterface::class);
		$request->method('getQueryParams')->willReturn(['offset' => '10', 'limit' => '20']);

		$params = $this->service->getPaginationParams($request);

		$this->assertEquals(10, $params['offset']);
		$this->assertEquals(20, $params['limit']);
	}

	public function testGetPaginationParamsEnforcesMaxLimit(): void {
		$request = $this->createStub(ServerRequestInterface::class);
		$request->method('getQueryParams')->willReturn(['limit' => '999']);

		$params = $this->service->getPaginationParams($request);

		$this->assertEquals(PaginationService::MAX_LIMIT, $params['limit']);
	}

	public function testBuildPaginationMetaReturnsCorrectStructure(): void {
		$meta = $this->service->buildPaginationMeta(100, 10, 20);

		$this->assertEquals(100, $meta['total']);
		$this->assertEquals(10, $meta['offset']);
		$this->assertEquals(20, $meta['limit']);
		$this->assertEquals(20, $meta['count']);
	}

	public function testBuildPaginationMetaHandlesLastPage(): void {
		$meta = $this->service->buildPaginationMeta(25, 20, 10);

		$this->assertEquals(25, $meta['total']);
		$this->assertEquals(20, $meta['offset']);
		$this->assertEquals(10, $meta['limit']);
		$this->assertEquals(5, $meta['count']);
	}
}
