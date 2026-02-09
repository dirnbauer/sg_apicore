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

use SGalinski\SgApiCore\Service\RateLimitService;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Test case for RateLimitService
 */
class RateLimitServiceTest extends UnitTestCase {
	public function testConsumeCreatesNewWindow(): void {
		$connection = $this->createMock(Connection::class);
		$state = FALSE;

		$connection->expects($this->once())->method('beginTransaction');
		$connection->expects($this->once())->method('commit');
		$connection->expects($this->never())->method('rollBack');

		$connection->method('fetchAssociative')->willReturnCallback(function ($query, $params) use (&$state) {
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
		$now = time();
		$result = $service->consume('api:tenant:ip:127.0.0.1', 2, 60);

		$this->assertTrue($result['allowed']);
		$this->assertSame(2, $result['limit']);
		$this->assertSame(1, $result['remaining']);
		$this->assertTrue($result['reset'] >= $now);
		$this->assertTrue($result['reset'] <= $now + 60);
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

	public function testConsumeAllowsBurstCapacity(): void {
		$connection = $this->createMock(Connection::class);
		$state = FALSE;

		$connection->expects($this->once())->method('beginTransaction');
		$connection->expects($this->once())->method('commit');
		$connection->expects($this->never())->method('rollBack');

		$connection->method('fetchAssociative')->willReturnCallback(function ($query, $params) use (&$state) {
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
		$result = $service->consume('api:tenant:ip:127.0.0.1', 2, 60, 3);

		$this->assertTrue($result['allowed']);
		$this->assertSame(5, $result['limit']);
		$this->assertSame(4, $result['remaining']);
		$this->assertSame(3, $result['burst']);
	}
}
