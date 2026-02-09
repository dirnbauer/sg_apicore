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
	public function testDashboardBoundsApplyCutoff(): void {
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

		$expectedCutoff = $now - (30 * 86400);
		$this->assertTrue(abs($service->lastCutoff - $expectedCutoff) < 3);
	}
}

class TestableRateLimitDashboardService extends RateLimitDashboardService {
	public array $rows = [];
	public ?int $lastCutoff = NULL;

	public function buildCountersForTest(array $apiOverrides, int $defaultEffectiveLimit): array {
		return $this->buildCounters($apiOverrides, $defaultEffectiveLimit);
	}

	protected function fetchRateLimitRows(int $cutoff): array {
		$this->lastCutoff = $cutoff;
		return $this->rows;
	}
}
