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
	 * @var \Psr\Log\LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	protected $logger;

	/**
	 * @var \Psr\Log\LoggerInterface|\PHPUnit\Framework\MockObject\Stub
	 */
	protected $loggerStub;

	/**
	 * @var ExtensionConfiguration|\PHPUnit\Framework\MockObject\MockObject
	 */
	protected $extensionConfiguration;

	protected function setUp(): void {
		parent::setUp();
		$this->logManager = $this->createStub(LogManager::class);
		$this->logger = $this->createMock(\Psr\Log\LoggerInterface::class);
		$this->loggerStub = $this->createStub(\Psr\Log\LoggerInterface::class);
		$this->logManager->method('getLogger')->willReturn($this->loggerStub);
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

	public function testLogErrorCallsLoggerWhenEnabled(): void {
		$reflection = new \ReflectionClass(LogService::class);
		$property = $reflection->getProperty('logger');
		$property->setAccessible(TRUE);
		$property->setValue($this->service, $this->logger);

		$this->extensionConfiguration->method('isLoggingEnabled')->willReturn(TRUE);

		$this->logger->expects($this->once())->method('error')->with('Test Error', []);

		$this->service->logError('Test Error');
	}

	public function testLogExceptionCallsLoggerWithContext(): void {
		$reflection = new \ReflectionClass(LogService::class);
		$property = $reflection->getProperty('logger');
		$property->setAccessible(TRUE);
		$property->setValue($this->service, $this->logger);

		$this->extensionConfiguration->method('isLoggingEnabled')->willReturn(TRUE);

		$exception = new \Exception('Test Exception');
		$request = $this->createStub(\Psr\Http\Message\ServerRequestInterface::class);
		$request->method('getAttribute')->willReturnMap([
			['api.requestId', '', 'req-123'],
			['language', NULL, NULL]
		]);
		$uri = new \TYPO3\CMS\Core\Http\Uri('https://example.org/api/v1/test');
		$request->method('getUri')->willReturn($uri);
		$request->method('getMethod')->willReturn('GET');

		$this->logger->expects($this->once())
			->method('critical')
			->with('Test Exception', $this->callback(function ($context) {
				return $context['requestId'] === 'req-123' && $context['method'] === 'GET';
			}));

		$this->service->logException($exception, $request);
	}

	public function testLogRequestResponseCallsLoggerWithRedactedData(): void {
		$reflection = new \ReflectionClass(LogService::class);
		$property = $reflection->getProperty('logger');
		$property->setAccessible(TRUE);
		$property->setValue($this->service, $this->logger);

		$this->extensionConfiguration->method('isLoggingEnabled')->willReturn(TRUE);
		$this->extensionConfiguration->method('getRedactKeys')->willReturn(['password']);
		$this->extensionConfiguration->method('isLogBodyEnabled')->willReturn(TRUE);

		$request = $this->createStub(\Psr\Http\Message\ServerRequestInterface::class);
		$request->method('getMethod')->willReturn('POST');
		$request->method('getUri')->willReturn(new \TYPO3\CMS\Core\Http\Uri('https://example.org/api/v1/test'));
		$request->method('getParsedBody')->willReturn(['password' => 'secret']);
		$request->method('getAttribute')->willReturnMap([
			['api.requestId', '', 'req-123'],
			['api.id', 'global', 'public'],
			['api.tenant', NULL, NULL],
			['language', NULL, NULL]
		]);

		$response = $this->createStub(\Psr\Http\Message\ResponseInterface::class);
		$response->method('getStatusCode')->willReturn(200);

		$this->logger->expects($this->once())
			->method('info')
			->with($this->anything(), $this->callback(function ($context) {
				return $context['requestBody']['password'] === '***REDACTED***';
			}));

		$this->service->logRequestResponse($request, $response, 0.5);
	}
}
