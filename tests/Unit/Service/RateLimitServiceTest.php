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

use TYPO3\CMS\Core\Database\Connection;
use SGalinski\SgApiCore\Service\RateLimitService;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Test case for RateLimitService
 */
class RateLimitServiceTest extends UnitTestCase {
	public function testConsumeCreatesNewWindow(): void {
		$connection = $this->createMock(Connection::class);
		$state = NULL;

		$connection->expects($this->once())->method('beginTransaction');
		$connection->expects($this->once())->method('commit');
		$connection->expects($this->never())->method('rollBack');

		$connection->method('fetchAssociative')->willReturnCallback(function () use (&$state) {
			return $state;
		});
		$connection->method('insert')->willReturnCallback(function ($table, array $data) use (&$state) {
			$state = [
				'hits' => $data['hits'],
				'window_start' => $data['window_start'],
			];
			return 1;
		});

		$connectionPool = $this->createStub(ConnectionPool::class);
		$connectionPool->method('getConnectionForTable')->willReturn($connection);

		$service = new RateLimitService($connectionPool);
		$result = $service->consume('api:tenant:ip:127.0.0.1', 2, 60);

		$this->assertTrue($result['allowed']);
		$this->assertSame(2, $result['limit']);
		$this->assertSame(1, $result['remaining']);
		$this->assertGreaterThanOrEqual(time(), $result['reset']);
		$this->assertLessThanOrEqual(time() + 60, $result['reset']);
	}

	public function testConsumeBlocksWhenLimitReached(): void {
		$connection = $this->createMock(Connection::class);
		$windowSeconds = 60;
		$windowStart = time() - (time() % $windowSeconds);
		$state = [
			'hits' => 2,
			'window_start' => $windowStart,
		];

		$connection->expects($this->once())->method('beginTransaction');
		$connection->expects($this->once())->method('commit');
		$connection->expects($this->never())->method('rollBack');
		$connection->expects($this->never())->method('update');
		$connection->expects($this->never())->method('insert');

		$connection->method('fetchAssociative')->willReturn($state);

		$connectionPool = $this->createStub(ConnectionPool::class);
		$connectionPool->method('getConnectionForTable')->willReturn($connection);

		$service = new RateLimitService($connectionPool);
		$result = $service->consume('api:tenant:ip:127.0.0.1', 2, $windowSeconds);

		$this->assertFalse($result['allowed']);
		$this->assertSame(2, $result['limit']);
		$this->assertSame(0, $result['remaining']);
	}
}
