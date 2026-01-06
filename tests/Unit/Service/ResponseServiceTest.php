<?php

namespace SGalinski\SgApiCore\Tests\Unit\Service;

use SGalinski\SgApiCore\Configuration\ExtensionConfiguration;
use SGalinski\SgApiCore\Service\ResponseService;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Test case for ResponseService
 */
class ResponseServiceTest extends UnitTestCase {
	public function testCreateSuccessResponseWithoutEnvelope(): void {
		$config = $this->createStub(ExtensionConfiguration::class);
		$config->method('isResponseEnvelopeEnabled')->willReturn(FALSE);

		$service = new ResponseService($config);
		$data = ['id' => 123];
		$response = $service->createSuccessResponse($data);

		$this->assertInstanceOf(JsonResponse::class, $response);
		$this->assertEquals(200, $response->getStatusCode());
		$this->assertEquals('{"id":123}', (string) $response->getBody());
	}

	public function testCreateSuccessResponseWithEnvelope(): void {
		$config = $this->createStub(ExtensionConfiguration::class);
		$config->method('isResponseEnvelopeEnabled')->willReturn(TRUE);

		$service = new ResponseService($config);
		$data = ['id' => 123];
		$meta = ['total' => 1];
		$response = $service->createSuccessResponse($data, $meta);

		$this->assertInstanceOf(JsonResponse::class, $response);
		$this->assertEquals(200, $response->getStatusCode());
		$this->assertEquals('{"data":{"id":123},"meta":{"total":1}}', (string) $response->getBody());
	}

	public function testCreateErrorResponse(): void {
		$config = $this->createStub(ExtensionConfiguration::class);
		$service = new ResponseService($config);

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
