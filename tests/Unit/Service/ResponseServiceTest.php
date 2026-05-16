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

use SGalinski\SgApiCore\Attribute\ApiLegacyMode;
use SGalinski\SgApiCore\Configuration\ExtensionConfiguration;
use SGalinski\SgApiCore\Service\ResponseService;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Test case for ResponseService
 */
#[\PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations]
class ResponseServiceTest extends UnitTestCase {
	public function testCreateSuccessResponseWithoutEnvelope(): void {
		$config = $this->createStub(ExtensionConfiguration::class);
		$config->method('isResponseEnvelopeEnabled')->willReturn(FALSE);
		$languageServiceFactory = $this->createStub(LanguageServiceFactory::class);

		$service = new ResponseService($config, $languageServiceFactory);
		$data = ['id' => 123];
		$response = $service->createSuccessResponse($data);

		$this->assertInstanceOf(JsonResponse::class, $response);
		$this->assertEquals(200, $response->getStatusCode());
		$this->assertEquals('{"id":123}', (string) $response->getBody());
	}

	public function testCreateSuccessResponseWithEnvelope(): void {
		$config = $this->createStub(ExtensionConfiguration::class);
		$config->method('isResponseEnvelopeEnabled')->willReturn(TRUE);
		$languageServiceFactory = $this->createStub(LanguageServiceFactory::class);

		$service = new ResponseService($config, $languageServiceFactory);
		$data = ['id' => 123];
		$meta = ['total' => 1];
		$response = $service->createSuccessResponse($data, $meta);

		$this->assertInstanceOf(JsonResponse::class, $response);
		$this->assertEquals(200, $response->getStatusCode());
		$this->assertEquals('{"data":{"id":123},"meta":{"total":1}}', (string) $response->getBody());
	}

	public function testCreateErrorResponse(): void {
		$config = $this->createStub(ExtensionConfiguration::class);
		$languageServiceFactory = $this->createStub(LanguageServiceFactory::class);
		$service = new ResponseService($config, $languageServiceFactory);

		$response = $service->createErrorResponse('Test Error', 'Detailed message', 400);

		$this->assertInstanceOf(JsonResponse::class, $response);
		$this->assertEquals(400, $response->getStatusCode());
		$this->assertEquals('application/problem+json', $response->getHeaderLine('Content-Type'));

		$body = json_decode((string) $response->getBody(), TRUE);
		$this->assertEquals('Test Error', $body['title']);
		$this->assertEquals('Detailed message', $body['detail']);
		$this->assertEquals(400, $body['status']);
		$this->assertEquals('about:blank', $body['type']);
	}

	public function testCreateErrorResponseWithLegacyFormat(): void {
		$config = $this->createStub(ExtensionConfiguration::class);
		$languageServiceFactory = $this->createStub(LanguageServiceFactory::class);
		$service = new ResponseService($config, $languageServiceFactory);

		// Test for generic legacy format
		$legacyMode = new ApiLegacyMode(source: 'generic', legacyErrorFormat: TRUE);
		$response = $service->createErrorResponse('Test Error', 'Detailed message', 403, legacyMode: $legacyMode);

		$body = json_decode((string) $response->getBody(), TRUE);
		$this->assertEquals('Test Error', $body['error']);
		$this->assertEquals('Detailed message', $body['message']);
		$this->assertEquals(403, $body['code']);

		// Test for sg_rest legacy format
		$legacyModeSgRest = new ApiLegacyMode(source: 'sg_rest', legacyErrorFormat: TRUE);
		$responseSgRest = $service->createErrorResponse(
			'Test Error',
			'Detailed message',
			401,
			legacyMode: $legacyModeSgRest
		);
		$bodySgRest = json_decode((string) $responseSgRest->getBody(), TRUE);
		$this->assertEquals('Detailed message', $bodySgRest['message']);
		$this->assertArrayNotHasKey('error', $bodySgRest);
	}

	public function testCreateLocalizedErrorResponse(): void {
		$config = $this->createStub(ExtensionConfiguration::class);
		$languageServiceFactory = $this->createMock(LanguageServiceFactory::class);
		$languageService = $this->createMock(LanguageService::class);

		$languageServiceFactory->method('create')->willReturn($languageService);
		$languageService->method('sL')->willReturnCallback(function ($key) {
			if ($key === 'LLL:EXT:sg_apicore/Resources/Private/Language/locallang.xlf:error.title') {
				return 'Translated Title';
			}
			return $key;
		});

		$service = new ResponseService($config, $languageServiceFactory);
		$response = $service->createLocalizedErrorResponse(
			'LLL:EXT:sg_apicore/Resources/Private/Language/locallang.xlf:error.title',
			'Detailed message',
			500
		);

		$body = json_decode((string) $response->getBody(), TRUE);
		$this->assertEquals('Translated Title', $body['title']);
	}
}
