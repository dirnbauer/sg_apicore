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

use SGalinski\SgApiCore\Configuration\ExtensionConfiguration;
use SGalinski\SgApiCore\Service\LogService;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Test case for LogService
 */
class LogServiceTest extends UnitTestCase {
	/**
	 * @var LogService
	 */
	protected LogService $service;

	/**
	 * @var LogManager|\PHPUnit\Framework\MockObject\MockObject
	 */
	protected $logManager;

	/**
	 * @var ExtensionConfiguration|\PHPUnit\Framework\MockObject\MockObject
	 */
	protected $extensionConfiguration;

	protected function setUp(): void {
		parent::setUp();
		$this->logManager = $this->createStub(LogManager::class);
		$this->logManager->method('getLogger')->willReturn($this->createStub(\Psr\Log\LoggerInterface::class));
		$this->extensionConfiguration = $this->createStub(ExtensionConfiguration::class);
		$this->service = new LogService($this->logManager, $this->extensionConfiguration);
	}

	public function testRedactMasksSensitiveKeysInArray(): void {
		$data = [
			'username' => 'user123',
			'password' => 'secret123',
			'token' => 'abc-123',
			'nested' => [
				'secret' => 'top-secret',
				'safe' => 'ok'
			]
		];
		$redactKeys = ['password', 'token', 'secret'];

		$result = $this->service->redact($data, $redactKeys);

		$this->assertEquals('user123', $result['username']);
		$this->assertEquals('***REDACTED***', $result['password']);
		$this->assertEquals('***REDACTED***', $result['token']);
		$this->assertEquals('***REDACTED***', $result['nested']['secret']);
		$this->assertEquals('ok', $result['nested']['safe']);
	}

	public function testRedactHandlesJsonStrings(): void {
		$data = '{"password":"123","safe":"ok"}';
		$redactKeys = ['password'];

		$result = $this->service->redact($data, $redactKeys);

		$this->assertStringContainsString('***REDACTED***', $result);
		$this->assertStringContainsString('"safe":"ok"', $result);

		$decoded = json_decode($result, TRUE);
		$this->assertEquals('***REDACTED***', $decoded['password']);
	}

	public function testRedactReturnsOriginalOnInvalidJson(): void {
		$data = 'not a json { "foo"';
		$redactKeys = ['foo'];

		$result = $this->service->redact($data, $redactKeys);

		$this->assertEquals($data, $result);
	}
}
