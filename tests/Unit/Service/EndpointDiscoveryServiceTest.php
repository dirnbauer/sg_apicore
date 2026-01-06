<?php

namespace SGalinski\SgApiCore\Tests\Unit\Service;

use SGalinski\SgApiCore\Attribute\ApiBodyParam;
use SGalinski\SgApiCore\Attribute\ApiEndpoint;
use SGalinski\SgApiCore\Attribute\ApiPathParam;
use SGalinski\SgApiCore\Attribute\ApiQueryParam;
use SGalinski\SgApiCore\Attribute\ApiResponse;
use SGalinski\SgApiCore\Attribute\ApiRoute;
use SGalinski\SgApiCore\Attribute\RequireScopes;
use SGalinski\SgApiCore\Service\EndpointDiscoveryService;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Test case for EndpointDiscoveryService
 */
class EndpointDiscoveryServiceTest extends UnitTestCase {
	public function testGetAllEndpointsReturnsCompleteData(): void {
		$controllers = new \ArrayIterator([new DiscoveryMockController()]);
		$service = new EndpointDiscoveryService($controllers);
		$endpoints = $service->getAllEndpoints();

		$this->assertCount(1, $endpoints);
		$endpoint = $endpoints[0];

		$this->assertEquals(['public'], $endpoint['apiId']);
		$this->assertEquals(['1'], $endpoint['version']);
		$this->assertEquals('/discovery-test/{id}', $endpoint['path']);
		$this->assertEquals(['POST'], $endpoint['methods']);
		$this->assertEquals('Test Summary', $endpoint['summary']);
		$this->assertEquals('Test Description', $endpoint['description']);
		$this->assertEquals(['TestTag'], $endpoint['tags']);
		$this->assertEquals(['read', 'write'], $endpoint['scopes']);
		$this->assertEquals(DiscoveryMockController::class, $endpoint['controller']);
		$this->assertEquals('testAction', $endpoint['action']);

		$this->assertCount(1, $endpoint['bodyParams']);
		$this->assertInstanceOf(ApiBodyParam::class, $endpoint['bodyParams'][0]);
		$this->assertEquals('bodyParam', $endpoint['bodyParams'][0]->name);

		$this->assertCount(1, $endpoint['queryParams']);
		$this->assertInstanceOf(ApiQueryParam::class, $endpoint['queryParams'][0]);
		$this->assertEquals('queryParam', $endpoint['queryParams'][0]->name);

		$this->assertCount(1, $endpoint['pathParams']);
		$this->assertInstanceOf(ApiPathParam::class, $endpoint['pathParams'][0]);
		$this->assertEquals('id', $endpoint['pathParams'][0]->name);

		$this->assertCount(1, $endpoint['responses']);
		$this->assertInstanceOf(ApiResponse::class, $endpoint['responses'][0]);
		$this->assertEquals(200, $endpoint['responses'][0]->status);
	}
}

/**
 * Mock controller for discovery testing
 */
class DiscoveryMockController {
	#[ApiRoute(path: '/discovery-test/{id}', methods: ['POST'], apiId: 'public', version: '1')]
	#[ApiEndpoint(summary: 'Test Summary', description: 'Test Description', tags: ['TestTag'])]
	#[RequireScopes(['read', 'write'])]
	#[ApiBodyParam(name: 'bodyParam', type: 'string')]
	#[ApiQueryParam(name: 'queryParam', type: 'string')]
	#[ApiPathParam(name: 'id', type: 'integer')]
	#[ApiResponse(status: 200, description: 'Success')]
	public function testAction(): void {
	}
}
