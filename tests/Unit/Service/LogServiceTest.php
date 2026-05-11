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

use Psr\Log\LoggerInterface;
use ReflectionClass;
use Exception;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Http\Uri;
use Psr\Http\Message\ResponseInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
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
     * @var LogManager|MockObject
     */
    protected $logManager;

	/**
     * @var LoggerInterface|MockObject
     */
    protected $logger;

	/**
     * @var LoggerInterface|Stub
     */
    protected $loggerStub;

	/**
     * @var ExtensionConfiguration|MockObject
     */
    protected $extensionConfiguration;

	protected function setUp(): void {
		parent::setUp();
		$this->logManager = $this->createStub(LogManager::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->loggerStub = $this->createStub(LoggerInterface::class);
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
				'safe' => 'ok',
			],
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
		$reflection = new ReflectionClass(LogService::class);
		$property = $reflection->getProperty('logger');
		$property->setAccessible(TRUE);
		$property->setValue($this->service, $this->logger);

		$this->extensionConfiguration->method('isLoggingEnabled')->willReturn(TRUE);

		$this->logger->expects($this->once())->method('log')->with('error', 'Test Error', []);

		$this->service->logError('Test Error');
	}

	public function testLogExceptionCallsLoggerWithContext(): void {
		$reflection = new ReflectionClass(LogService::class);
		$property = $reflection->getProperty('logger');
		$property->setAccessible(TRUE);
		$property->setValue($this->service, $this->logger);

		$this->extensionConfiguration->method('isLoggingEnabled')->willReturn(TRUE);

		$exception = new Exception('Test Exception');
		$request = $this->createStub(ServerRequestInterface::class);
		$request->method('getAttribute')->willReturnMap([['api.requestId', '', 'req-123'], ['language', NULL, NULL]]);
		$uri = new Uri('https://example.org/api/v1/test');
		$request->method('getUri')->willReturn($uri);
		$request->method('getMethod')->willReturn('GET');

		$this->logger->expects($this->once())
			->method('critical')
			->with(
				'Test Exception',
				$this->callback(function ($context) {
					return $context['requestId'] === 'req-123' && $context['method'] === 'GET';
				})
			);

		$this->service->logException($exception, $request);
	}

	public function testLogRequestResponseCallsLoggerWithRedactedData(): void {
		$reflection = new ReflectionClass(LogService::class);
		$property = $reflection->getProperty('logger');
		$property->setAccessible(TRUE);
		$property->setValue($this->service, $this->logger);

		$this->extensionConfiguration->method('isLoggingEnabled')->willReturn(TRUE);
		$this->extensionConfiguration->method('getRedactKeys')->willReturn(['password']);
		$this->extensionConfiguration->method('isLogBodyEnabled')->willReturn(TRUE);

		$request = $this->createStub(ServerRequestInterface::class);
		$request->method('getMethod')->willReturn('POST');
		$request->method('getUri')->willReturn(new Uri('https://example.org/api/v1/test'));
		$request->method('getParsedBody')->willReturn(['password' => 'secret']);
		$request->method('getAttribute')->willReturnMap([
			['api.requestId', '', 'req-123'],
			['api.id', 'global', 'public'],
			['api.tenant', NULL, NULL],
			['language', NULL, NULL],
		]);

		$response = $this->createStub(ResponseInterface::class);
		$response->method('getStatusCode')->willReturn(200);

		$this->logger->expects($this->once())
			->method('info')
			->with(
				$this->anything(),
				$this->callback(function ($context) {
					return $context['requestBody']['password'] === '***REDACTED***';
				})
			);

		$this->service->logRequestResponse($request, $response, 0.5);
	}

	public function testTruncateString(): void {
		$reflection = new ReflectionClass(LogService::class);
		$method = $reflection->getMethod('truncateString');
		$method->setAccessible(TRUE);

		$longString = str_repeat('a', 100);
		$result = $method->invoke($this->service, $longString, 50);

		$this->assertEquals(50 + strlen('... [truncated]'), strlen($result));
		$this->assertStringContainsString('... [truncated]', $result);
		$this->assertEquals(0, strpos(strrev($result), strrev('... [truncated]')));
	}

	public function testTruncateLogDataWithArray(): void {
		$reflection = new ReflectionClass(LogService::class);
		$method = $reflection->getMethod('truncateLogData');
		$method->setAccessible(TRUE);

		$data = ['foo' => str_repeat('b', 100)];
		$result = $method->invoke($this->service, $data, 50);

		$this->assertIsString($result);
		$this->assertStringContainsString('... [truncated]', $result);
		$this->assertEquals(0, strpos(strrev($result), strrev('... [truncated]')));
	}

	public function testRedactBearerToken(): void {
		$data = 'Bearer some-secret-token';
		$result = $this->service->redact($data, []);
		$this->assertEquals('Bearer ***REDACTED***', $result);
	}

	public function testRedactAuthorizationHeader(): void {
		$data = ['Authorization' => 'Bearer some-secret-token'];
		$result = $this->service->redact($data, []);
		$this->assertEquals('***REDACTED***', $result['Authorization']);
	}
}
