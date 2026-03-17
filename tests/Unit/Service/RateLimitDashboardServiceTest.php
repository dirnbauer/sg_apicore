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

namespace SGalinski\SgApiCore\Tests\Unit\Service;

use SGalinski\SgApiCore\Configuration\ExtensionConfiguration;
use SGalinski\SgApiCore\Service\ApiRegistry;
use SGalinski\SgApiCore\Service\RateLimitDashboardService;
use SGalinski\SgApiCore\Service\ResourceRegistry;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Test case for RateLimitDashboardService
 */
class RateLimitDashboardServiceTest extends UnitTestCase {
	public function testDashboardShowsOnlyActiveSessions(): void {
		$extensionConfiguration = $this->createStub(ExtensionConfiguration::class);
		$apiRegistry = $this->createStub(ApiRegistry::class);
		$resourceRegistry = $this->createStub(ResourceRegistry::class);
		$connectionPool = $this->createStub(ConnectionPool::class);

		$service = new TestableRateLimitDashboardService(
			$extensionConfiguration,
			$apiRegistry,
			$resourceRegistry,
			$connectionPool
		);

		$now = time();
		$service->rows = [
			[
				'identifier' => 'public:tenant-1:ip:127.0.0.1',
				'hits' => 5,
				'window_start' => $now - 120,
				'expires_at' => 1,
			],
			[
				'identifier' => 'public:tenant-1:ip:127.0.0.2',
				'hits' => 3,
				'window_start' => $now - 120,
				'expires_at' => $now + 120,
			],
		];

		$counters = $service->buildCountersForTest([], 10);

		$this->assertCount(1, $counters);
		$this->assertSame('public:tenant-1:ip:127.0.0.2', $counters[0]['identifier']);

		$this->assertTrue(abs($service->lastCutoff - $now) < 3);
	}

	public function testBuildCountersAppliesFiltersAndSortsByRemainingAscending(): void {
		$service = $this->createDashboardService();
		$now = time();
		$service->rows = [
			[
				'identifier' => 'partner:tenant-a:ip:127.0.0.10',
				'hits' => 9,
				'window_start' => $now - 120,
				'expires_at' => $now + 120,
			],
			[
				'identifier' => 'partner:tenant-a:ip:127.0.0.11',
				'hits' => 2,
				'window_start' => $now - 120,
				'expires_at' => $now + 120,
			],
			[
				'identifier' => 'partner:tenant-b:ip:127.0.0.12',
				'hits' => 7,
				'window_start' => $now - 120,
				'expires_at' => $now + 120,
			],
		];

		$counters = $service->buildCountersForTest([], 10, [
			'apiId' => 'partner',
			'tenantId' => 'tenant-a',
			'subjectType' => 'ip',
		]);

		$this->assertCount(2, $counters);
		$this->assertSame('partner:tenant-a:ip:127.0.0.10', $counters[0]['identifier']);
		$this->assertSame('partner:tenant-a:ip:127.0.0.11', $counters[1]['identifier']);
		$this->assertSame(1, $counters[0]['remaining']);
		$this->assertSame(8, $counters[1]['remaining']);
	}

	public function testBuildCountersUsesHistoryCutoffWhenEnabled(): void {
		$service = $this->createDashboardService();
		$service->rows = [];
		$now = time();

		$service->buildCountersForTest([], 10, ['includeHistory' => TRUE]);

		$expectedCutoff = $now - 86400;
		$this->assertTrue(abs($service->lastCutoff - $expectedCutoff) < 3);
	}

	public function testPaginateCountersReturnsExpectedSliceAndMetadata(): void {
		$service = $this->createDashboardService();
		$counters = [
			['identifier' => 'a'],
			['identifier' => 'b'],
			['identifier' => 'c'],
		];

		$result = $service->paginateCountersForTest($counters, 2, 1);

		$this->assertCount(1, $result['items']);
		$this->assertSame('b', $result['items'][0]['identifier']);
		$this->assertSame(2, $result['pagination']['currentPage']);
		$this->assertSame(3, $result['pagination']['totalPages']);
		$this->assertSame(2, $result['pagination']['fromItem']);
		$this->assertSame(2, $result['pagination']['toItem']);
	}

	protected function createDashboardService(): TestableRateLimitDashboardService {
		return new TestableRateLimitDashboardService(
			$this->createStub(ExtensionConfiguration::class),
			$this->createStub(ApiRegistry::class),
			$this->createStub(ResourceRegistry::class),
			$this->createStub(ConnectionPool::class)
		);
	}
}

class TestableRateLimitDashboardService extends RateLimitDashboardService {
	public array $rows = [];
	public ?int $lastCutoff = NULL;

	public function buildCountersForTest(array $apiOverrides, int $defaultEffectiveLimit, array $filters = []): array {
		return $this->buildCounters($apiOverrides, $defaultEffectiveLimit, $filters);
	}

	/**
	 * @param array<int, array<string, mixed>> $counters
	 * @return array{items: array<int, array<string, mixed>>, pagination: array<string, int|bool>}
	 */
	public function paginateCountersForTest(array $counters, int $page, int $perPage): array {
		return $this->paginateCounters($counters, $page, $perPage);
	}

	protected function fetchRateLimitRows(int $cutoff): array {
		$this->lastCutoff = $cutoff;
		return $this->rows;
	}
}
