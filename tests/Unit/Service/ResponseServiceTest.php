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
use SGalinski\SgApiCore\Service\ResponseService;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Test case for ResponseService
 */
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
}
