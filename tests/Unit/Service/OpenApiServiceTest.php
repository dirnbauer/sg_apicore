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

use SGalinski\SgApiCore\Attribute\ApiBodyParam;
use SGalinski\SgApiCore\Attribute\ApiEndpoint;
use SGalinski\SgApiCore\Attribute\ApiPathParam;
use SGalinski\SgApiCore\Attribute\ApiQueryParam;
use SGalinski\SgApiCore\Attribute\ApiResponse;
use SGalinski\SgApiCore\Attribute\ApiRoute;
use SGalinski\SgApiCore\Configuration\ExtensionConfiguration;
use SGalinski\SgApiCore\Service\ApiRegistry;
use SGalinski\SgApiCore\Service\EndpointDiscoveryService;
use SGalinski\SgApiCore\Service\OpenApiService;
use SGalinski\SgApiCore\Service\ResourceRegistry;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Test case for OpenApiService
 */
class OpenApiServiceTest extends UnitTestCase {
	protected function getDiscoveryService(iterable $controllers): EndpointDiscoveryService {
		$resourceRegistry = $this->createStub(ResourceRegistry::class);
		$resourceRegistry->method('getResources')->willReturn([]);

		$cache = $this->createMock(FrontendInterface::class);
		$cache->method('get')->willReturn(NULL);
		$cacheManager = $this->createMock(CacheManager::class);
		$cacheManager->method('getCache')->with('sg_apicore_discovery')->willReturn($cache);

		return new EndpointDiscoveryService($controllers, $resourceRegistry, $cacheManager);
	}

	public function testGenerateSpecContainsPaths(): void {
		$apiRegistry = $this->createStub(ApiRegistry::class);
		$apiRegistry->method('getSecurityConfig')->willReturn(['authMode' => 'public']);

		$extensionConfiguration = $this->createStub(ExtensionConfiguration::class);
		$extensionConfiguration->method('getApiPathPrefix')->willReturn('/api/');

		$controllers = new \ArrayIterator([new MockOpenApiController()]);
		$discoveryService = $this->getDiscoveryService($controllers);
		$service = new OpenApiService($discoveryService, $apiRegistry, $extensionConfiguration);
		$spec = $service->generateSpec('public', '1');

		$this->assertEquals('3.0.3', $spec['openapi']);
		$this->assertArrayHasKey('/test', $spec['paths']);
		$this->assertArrayHasKey('get', $spec['paths']['/test']);
		$this->assertEquals('Test summary', $spec['paths']['/test']['get']['summary']);
		$this->assertEquals('/api/public/v1', $spec['servers'][0]['url']);
	}

	public function testGenerateSpecUsesProvidedBaseUrl(): void {
		$apiRegistry = $this->createStub(ApiRegistry::class);
		$apiRegistry->method('getSecurityConfig')->willReturn(['authMode' => 'public']);

		$extensionConfiguration = $this->createStub(ExtensionConfiguration::class);

		$controllers = new \ArrayIterator([new MockOpenApiController()]);
		$discoveryService = $this->getDiscoveryService($controllers);
		$service = new OpenApiService($discoveryService, $apiRegistry, $extensionConfiguration);
		$spec = $service->generateSpec('public', '1', 'https://example.com/api/public/v1');

		$this->assertEquals('https://example.com/api/public/v1', $spec['servers'][0]['url']);
	}

	public function testGenerateSpecFiltersByApiId(): void {
		$apiRegistry = $this->createStub(ApiRegistry::class);
		$apiRegistry->method('getSecurityConfig')->willReturn(['authMode' => 'public']);

		$extensionConfiguration = $this->createStub(ExtensionConfiguration::class);

		$controllers = new \ArrayIterator([new MockOpenApiController()]);
		$discoveryService = $this->getDiscoveryService($controllers);
		$service = new OpenApiService($discoveryService, $apiRegistry, $extensionConfiguration);

		// 'public' api should have /test but not /partner-only
		$spec = $service->generateSpec('public', '1');
		$this->assertArrayHasKey('/test', $spec['paths']);
		$this->assertArrayNotHasKey('/partner-only', $spec['paths']);

		// 'partner' api should have /partner-only but not /test
		$specPartner = $service->generateSpec('partner', '1');
		$this->assertArrayHasKey('/partner-only', $specPartner['paths']);
		$this->assertArrayNotHasKey('/test', $specPartner['paths']);
	}

	public function testGenerateSpecFiltersHybridAuthModeCorrectly(): void {
		$apiRegistry = $this->createStub(ApiRegistry::class);
		$apiRegistry->method('getSecurityConfig')->willReturnMap([
			['public', '1', ['authMode' => 'public']],
			['user', '1', ['authMode' => 'user']],
		]);

		$extensionConfiguration = $this->createStub(ExtensionConfiguration::class);
		$controllers = new \ArrayIterator([new MockHybridController()]);
		$discoveryService = $this->getDiscoveryService($controllers);

		$service = new OpenApiService($discoveryService, $apiRegistry, $extensionConfiguration);

		// 'public' api should NOT have /hybrid
		$specPublic = $service->generateSpec('public', '1');
		$this->assertArrayNotHasKey('/hybrid', $specPublic['paths']);

		// 'user' api SHOULD have /hybrid
		$specUser = $service->generateSpec('user', '1');
		$this->assertArrayHasKey('/hybrid', $specUser['paths']);
	}

	public function testGenerateSpecContainsRequestBody(): void {
		$apiRegistry = $this->createStub(ApiRegistry::class);
		$apiRegistry->method('getSecurityConfig')->willReturn(['authMode' => 'public']);

		$extensionConfiguration = $this->createStub(ExtensionConfiguration::class);
		$extensionConfiguration->method('getApiPathPrefix')->willReturn('/api/');

		$controllers = new \ArrayIterator([new MockBodyParamController()]);
		$discoveryService = $this->getDiscoveryService($controllers);

		$service = new OpenApiService($discoveryService, $apiRegistry, $extensionConfiguration);
		$spec = $service->generateSpec('public', '1');

		$this->assertArrayHasKey('/post-test', $spec['paths']);
		$operation = $spec['paths']['/post-test']['post'];
		$this->assertArrayHasKey('requestBody', $operation);
		$this->assertTrue($operation['requestBody']['required']);
		$content = $operation['requestBody']['content']['application/json'];
		$schema = $content['schema'];
		$this->assertEquals('object', $schema['type']);
		$this->assertArrayHasKey('username', $schema['properties']);
		$this->assertEquals('string', $schema['properties']['username']['type']);
		$this->assertEquals('john_doe', $schema['properties']['username']['example']);
		$this->assertContains('username', $schema['required']);
	}

	public function testGenerateSpecContainsExamples(): void {
		$apiRegistry = $this->createStub(ApiRegistry::class);
		$apiRegistry->method('getSecurityConfig')->willReturn(['authMode' => 'public']);

		$extensionConfiguration = $this->createStub(ExtensionConfiguration::class);
		$extensionConfiguration->method('getApiPathPrefix')->willReturn('/api/');

		$controllers = new \ArrayIterator([new MockExampleController()]);
		$discoveryService = $this->getDiscoveryService($controllers);

		$service = new OpenApiService($discoveryService, $apiRegistry, $extensionConfiguration);
		$spec = $service->generateSpec('public', '1');
		$operation = $spec['paths']['/example/{id}']['get'];

		// Query Param Example (Index 0 in OpenApiService)
		$this->assertEquals('q', $operation['parameters'][0]['name']);
		$this->assertEquals('search-term', $operation['parameters'][0]['schema']['example']);

		// Path Param Example (Index 1 in OpenApiService)
		$this->assertEquals('id', $operation['parameters'][1]['name']);
		$this->assertEquals(123, $operation['parameters'][1]['schema']['example']);

		// Response Example
		$this->assertArrayHasKey('200', $operation['responses']);
		$this->assertEquals(['foo' => 'bar'], $operation['responses']['200']['content']['application/json']['example']);
	}

	public function testGenerateSpecFixesMissingDescriptionAndMissingArrayItems(): void {
		$apiRegistry = $this->createStub(ApiRegistry::class);
		$apiRegistry->method('getSecurityConfig')->willReturn(['authMode' => 'public']);

		$extensionConfiguration = $this->createStub(ExtensionConfiguration::class);
		$controllers = new \ArrayIterator([new MockSchemaErrorController()]);
		$discoveryService = $this->getDiscoveryService($controllers);

		$service = new OpenApiService($discoveryService, $apiRegistry, $extensionConfiguration);
		$spec = $service->generateSpec('public', '1');

		$operation = $spec['paths']['/error-test']['get'];

		// Check missing description is now empty string
		$this->assertIsString($operation['responses']['200']['description']);
		$this->assertIsString($operation['parameters'][0]['description']);

		// Check array items
		$this->assertArrayHasKey('items', $operation['parameters'][0]['schema']);
		$this->assertArrayHasKey('anyOf', $operation['parameters'][0]['schema']['items']);
		$this->assertEquals(
			[['type' => 'string'], ['type' => 'integer']],
			$operation['parameters'][0]['schema']['items']['anyOf']
		);

		$this->assertArrayHasKey('requestBody', $operation);
		$properties = $operation['requestBody']['content']['application/json']['schema']['properties'];
		$this->assertArrayHasKey('tags', $properties);
		$this->assertEquals('array', $properties['tags']['type']);
		$this->assertArrayHasKey('items', $properties['tags']);
		$this->assertArrayHasKey('anyOf', $properties['tags']['items']);
		$this->assertEquals(
			[['type' => 'string'], ['type' => 'integer']],
			$properties['tags']['items']['anyOf']
		);
	}
}

/**
 * Mock controller for testing
 */
class MockOpenApiController {
	#[ApiRoute(path: '/test', methods: ['GET'], apiId: 'public', version: '1')]
	#[ApiEndpoint(summary: 'Test summary')]
	public function testAction(): void {
	}

	#[ApiRoute(path: '/partner-only', methods: ['GET'], apiId: 'partner', version: '1')]
	public function partnerAction(): void {
	}
}

/**
 * Mock controller for testing schema errors
 */
class MockSchemaErrorController {
	#[ApiRoute(path: '/error-test', methods: ['GET'])]
	#[ApiQueryParam(name: 'list', type: 'array', description: NULL)]
	#[ApiBodyParam(name: 'tags', type: 'array', description: 'tags')]
	#[ApiResponse(status: 200, description: NULL)]
	public function errorAction(): void {
	}
}

/**
 * Mock controller for hybrid auth
 */
class MockHybridController {
	#[ApiRoute(path: '/hybrid', methods: ['GET'], authMode: ['user', 'public'])]
	public function hybridAction(): void {
	}
}

/**
 * Mock controller for body params
 */
class MockBodyParamController {
	#[ApiRoute(path: '/post-test', methods: ['POST'])]
	#[ApiBodyParam(name: 'username', required: TRUE, example: 'john_doe')]
	public function postAction(): void {
	}
}

/**
 * Mock controller for examples
 */
class MockExampleController {
	#[ApiRoute(path: '/example/{id}', methods: ['GET'])]
	#[ApiPathParam(name: 'id', type: 'integer', example: 123)]
	#[ApiQueryParam(name: 'q', example: 'search-term')]
	#[ApiResponse(status: 200, schema: 'object', example: ['foo' => 'bar'])]
	public function exampleAction(): void {
	}
}
