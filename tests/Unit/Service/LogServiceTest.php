<?php

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
