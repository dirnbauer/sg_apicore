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
use SGalinski\SgApiCore\Service\SchemaRegistry;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Test case for OpenApiService
 */
class OpenApiServiceTest extends UnitTestCase {
	public function testEnrichSchemaWithXTagField(): void {
		$apiRegistry = $this->createStub(ApiRegistry::class);
		$apiRegistry->method('getSecurityConfig')->willReturn(['authMode' => 'public']);

		$extensionConfiguration = $this->createStub(ExtensionConfiguration::class);
		$controllers = new \ArrayIterator([new MockGlobalSchemaController()]);
		$discoveryService = $this->getDiscoveryService($controllers);

		$GLOBALS['TCA']['tx_test_table'] = [
			'columns' => [
				'original_field' => [
					'label' => 'LLL:EXT:test/locallang.xlf:title'
				]
			]
		];

		$globalSchemas = [
			'GlobalObject' => [
				'type' => 'object',
				'properties' => [
					'remapped_field' => [
						'type' => 'string',
						'x-tca-field' => 'original_field'
					]
				]
			]
		];

		$schemaRegistry = $this->createStub(SchemaRegistry::class);
		$schemaRegistry->method('getSchemas')->willReturn($globalSchemas);
		$schemaRegistry->method('getTableNameForSchema')->with('public', 'GlobalObject')->willReturn('tx_test_table');

		$cache = $this->createStub(FrontendInterface::class);
		$cacheManager = $this->createStub(CacheManager::class);
		$cacheManager->method('getCache')->with('sg_apicore_discovery')->willReturn($cache);

		$service = new OpenApiService(
			$discoveryService,
			$apiRegistry,
			$schemaRegistry,
			$extensionConfiguration,
			$cacheManager
		);
		$spec = $service->generateSpec('public', '1');

		$schema = $spec['components']['schemas']['GlobalObject'];
		$this->assertEquals('Translated Title', $schema['properties']['remapped_field']['description']);

		unset($GLOBALS['TCA']['tx_test_table']);
	}

	public function testGenerateSchemaFromExampleEnrichesWithTca(): void {
		$apiRegistry = $this->createStub(ApiRegistry::class);
		$apiRegistry->method('getSecurityConfig')->willReturn(['authMode' => 'public']);

		$extensionConfiguration = $this->createStub(ExtensionConfiguration::class);
		$controllers = new \ArrayIterator([new MockTcaEnrichmentController()]);
		$discoveryService = $this->getDiscoveryService($controllers);

		$GLOBALS['TCA']['tx_test_table'] = [
			'columns' => [
				'title' => [
					'label' => 'LLL:EXT:test/locallang.xlf:title',
					'description' => 'A descriptive text'
				]
			]
		];

		$service = $this->getOpenApiService($discoveryService, $apiRegistry, $extensionConfiguration);
		$spec = $service->generateSpec('public', '1');

		$schema = $spec['paths']['/tca']['get']['responses']['200']['content']['application/json']['schema'];
		$this->assertEquals('Translated Title', $schema['properties']['title']['description']);

		unset($GLOBALS['TCA']['tx_test_table']);
	}

	public function testGenerateSpecWithGlobalSchema(): void {
		$apiRegistry = $this->createStub(ApiRegistry::class);
		$apiRegistry->method('getSecurityConfig')->willReturn(['authMode' => 'public']);

		$extensionConfiguration = $this->createStub(ExtensionConfiguration::class);
		$extensionConfiguration->method('getApiPathPrefix')->willReturn('/api/');

		$controllers = new \ArrayIterator([new MockGlobalSchemaController()]);
		$discoveryService = $this->getDiscoveryService($controllers);

		$globalSchemas = [
			'GlobalObject' => [
				'type' => 'object',
				'properties' => ['id' => ['type' => 'integer']]
			]
		];

		$service = $this->getOpenApiService($discoveryService, $apiRegistry, $extensionConfiguration, $globalSchemas);
		$spec = $service->generateSpec('public', '1');

		$this->assertArrayHasKey('components', $spec);
		$this->assertArrayHasKey('schemas', $spec['components']);
		$this->assertArrayHasKey('GlobalObject', $spec['components']['schemas']);
		$this->assertSame($globalSchemas['GlobalObject'], $spec['components']['schemas']['GlobalObject']);

		$this->assertArrayHasKey('/global', $spec['paths']);
		$this->assertArrayHasKey('get', $spec['paths']['/global']);
		$responses = $spec['paths']['/global']['get']['responses'];
		$this->assertArrayHasKey('200', $responses);
		$this->assertArrayHasKey('content', $responses['200']);
		$this->assertArrayHasKey('application/json', $responses['200']['content']);
		$schema = $responses['200']['content']['application/json']['schema'];
		$this->assertEquals('#/components/schemas/GlobalObject', $schema['$ref']);
	}

	public function testGenerateSpecContainsPaths(): void {
		$apiRegistry = $this->createStub(ApiRegistry::class);
		$apiRegistry->method('getSecurityConfig')->willReturn(['authMode' => 'public']);

		$extensionConfiguration = $this->createStub(ExtensionConfiguration::class);
		$extensionConfiguration->method('getApiPathPrefix')->willReturn('/api/');

		$controllers = new \ArrayIterator([new MockOpenApiController()]);
		$discoveryService = $this->getDiscoveryService($controllers);
		$service = $this->getOpenApiService($discoveryService, $apiRegistry, $extensionConfiguration);
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
		$service = $this->getOpenApiService($discoveryService, $apiRegistry, $extensionConfiguration);
		$spec = $service->generateSpec('public', '1', 'https://example.com/api/public/v1');

		$this->assertEquals('https://example.com/api/public/v1', $spec['servers'][0]['url']);
	}

	public function testGenerateSpecFiltersByApiId(): void {
		$apiRegistry = $this->createStub(ApiRegistry::class);
		$apiRegistry->method('getSecurityConfig')->willReturn(['authMode' => 'public']);

		$extensionConfiguration = $this->createStub(ExtensionConfiguration::class);

		$controllers = new \ArrayIterator([new MockOpenApiController()]);
		$discoveryService = $this->getDiscoveryService($controllers);
		$service = $this->getOpenApiService($discoveryService, $apiRegistry, $extensionConfiguration);

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
			['public', '', ['authMode' => 'public']],
			['partner', '1', ['authMode' => 'user']],
			['partner', '', ['authMode' => 'user']],
			['backend', '1', ['authMode' => 'backend']],
			['backend', '', ['authMode' => 'backend']]
		]);

		$extensionConfiguration = $this->createStub(ExtensionConfiguration::class);
		$controllers = new \ArrayIterator([
			new MockHybridController(),
			new MockUserOnlyController(),
			new MockBackendOnlyController(),
			new MockDefaultController(),
			new MockOpenApiController()
		]);
		$discoveryService = $this->getDiscoveryService($controllers);

		$openApiService = $this->getOpenApiService($discoveryService, $apiRegistry, $extensionConfiguration);

		// 1. Public API (authMode: public)
		$specPublic = $openApiService->generateSpec('public', '1');
		$this->assertArrayHasKey('/hybrid', $specPublic['paths'], 'Public API should contain hybrid endpoint');
		$this->assertArrayNotHasKey('/user-only', $specPublic['paths'], 'Public API should NOT contain user-only endpoint');
		$this->assertArrayNotHasKey(
			'/backend-only',
			$specPublic['paths'],
			'Public API should NOT contain backend-only endpoint'
		);
		$this->assertArrayHasKey('/default', $specPublic['paths'], 'Public API should contain default endpoint');
		$this->assertArrayHasKey(
			'/test',
			$specPublic['paths'],
			'Public API should contain OpenAPI docs endpoint (registered for public)'
		);

		// 2. Partner API (authMode: user)
		$specPartner = $openApiService->generateSpec('partner', '1');
		$this->assertArrayHasKey('/hybrid', $specPartner['paths'], 'Partner API should contain hybrid endpoint');
		$this->assertArrayHasKey('/user-only', $specPartner['paths'], 'Partner API should contain user-only endpoint');
		$this->assertArrayNotHasKey(
			'/backend-only',
			$specPartner['paths'],
			'Partner API should NOT contain backend-only endpoint'
		);
		$this->assertArrayHasKey('/default', $specPartner['paths'], 'Partner API should contain default endpoint');
		// Note: /test is only registered for apiId: 'public' in MockOpenApiController
		$this->assertArrayNotHasKey(
			'/test',
			$specPartner['paths'],
			'Partner API should NOT contain OpenAPI docs endpoint (only registered for public)'
		);

		// 3. Backend API (authMode: backend)
		$specBackend = $openApiService->generateSpec('backend', '1');
		$this->assertArrayNotHasKey('/hybrid', $specBackend['paths'], 'Backend API should NOT contain hybrid endpoint');
		$this->assertArrayNotHasKey('/user-only', $specBackend['paths'], 'Backend API should NOT contain user-only endpoint');
		$this->assertArrayHasKey('/backend-only', $specBackend['paths'], 'Backend API should contain backend-only endpoint');
		$this->assertArrayHasKey('/default', $specBackend['paths'], 'Backend API should contain default endpoint');
		$this->assertArrayNotHasKey(
			'/test',
			$specBackend['paths'],
			'Backend API should NOT contain OpenAPI docs endpoint (only registered for public)'
		);
	}

	public function testGenerateSpecContainsRequestBody(): void {
		$apiRegistry = $this->createStub(ApiRegistry::class);
		$apiRegistry->method('getSecurityConfig')->willReturn(['authMode' => 'public']);

		$extensionConfiguration = $this->createStub(ExtensionConfiguration::class);
		$extensionConfiguration->method('getApiPathPrefix')->willReturn('/api/');

		$controllers = new \ArrayIterator([new MockBodyParamController()]);
		$discoveryService = $this->getDiscoveryService($controllers);

		$service = $this->getOpenApiService($discoveryService, $apiRegistry, $extensionConfiguration);
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

		$service = $this->getOpenApiService($discoveryService, $apiRegistry, $extensionConfiguration);
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

		// Schema generated from Example
		$schema = $operation['responses']['200']['content']['application/json']['schema'];
		$this->assertEquals('object', $schema['type']);
		$this->assertArrayHasKey('properties', $schema);
		$this->assertEquals('string', $schema['properties']['foo']['type']);
	}

	public function testGenerateSpecGeneratesComplexSchemaFromExample(): void {
		$apiRegistry = $this->createStub(ApiRegistry::class);
		$apiRegistry->method('getSecurityConfig')->willReturn(['authMode' => 'public']);

		$extensionConfiguration = $this->createStub(ExtensionConfiguration::class);
		$controllers = new \ArrayIterator([new MockComplexExampleController()]);
		$discoveryService = $this->getDiscoveryService($controllers);

		$service = $this->getOpenApiService($discoveryService, $apiRegistry, $extensionConfiguration);
		$spec = $service->generateSpec('public', '1');
		$operation = $spec['paths']['/complex']['get'];

		$schema = $operation['responses']['200']['content']['application/json']['schema'];
		$this->assertEquals('object', $schema['type']);
		$this->assertEquals('string', $schema['properties']['title']['type']);
		$this->assertEquals('integer', $schema['properties']['count']['type']);
		$this->assertEquals('array', $schema['properties']['items']['type']);
		$this->assertEquals('object', $schema['properties']['items']['items']['type']);
		$this->assertEquals('integer', $schema['properties']['items']['items']['properties']['id']['type']);
	}

	public function testGenerateSpecContainsSecurity(): void {
		$apiRegistry = $this->createStub(ApiRegistry::class);
		$apiRegistry->method('getSecurityConfig')->willReturn(['authMode' => 'backend']);

		$extensionConfiguration = $this->createStub(ExtensionConfiguration::class);
		$controllers = new \ArrayIterator([new MockOpenApiController()]);
		$discoveryService = $this->getDiscoveryService($controllers);

		$openApiService = $this->getOpenApiService($discoveryService, $apiRegistry, $extensionConfiguration);
		$spec = $openApiService->generateSpec('partner', '1');

		$this->assertArrayHasKey('securitySchemes', $spec['components']);
		$this->assertArrayHasKey('cookieAuth', $spec['components']['securitySchemes']);
		$this->assertArrayHasKey('security', $spec);
		$this->assertEquals([['cookieAuth' => []]], $spec['security']);

		// Check route-level security
		$this->assertArrayHasKey('security', $spec['paths']['/partner-only']['get']);
		$this->assertEquals([['cookieAuth' => []]], $spec['paths']['/partner-only']['get']['security']);
	}

	public function testGenerateSpecWithHybridAuthMode(): void {
		$apiRegistry = $this->createStub(ApiRegistry::class);
		$apiRegistry->method('getSecurityConfig')->willReturn(['authMode' => 'public']);

		$extensionConfiguration = $this->createStub(ExtensionConfiguration::class);
		$controllers = new \ArrayIterator([new MockHybridController()]);
		$discoveryService = $this->getDiscoveryService($controllers);

		$openApiService = $this->getOpenApiService($discoveryService, $apiRegistry, $extensionConfiguration);
		$spec = $openApiService->generateSpec('public', '1');

		// Hybrid controller has authMode: ['user', 'public']
		// It should NOT have security field because 'public' is one of the options
		$this->assertArrayHasKey('/hybrid', $spec['paths']);
		$this->assertArrayNotHasKey('security', $spec['paths']['/hybrid']['get']);
	}

	public function testGenerateSpecWithTokenAuth(): void {
		$apiRegistry = $this->createStub(ApiRegistry::class);
		$apiRegistry->method('getSecurityConfig')->willReturn(['authMode' => 'token']);

		$extensionConfiguration = $this->createStub(ExtensionConfiguration::class);
		$controllers = new \ArrayIterator([new MockOpenApiController()]);
		$discoveryService = $this->getDiscoveryService($controllers);

		$openApiService = $this->getOpenApiService($discoveryService, $apiRegistry, $extensionConfiguration);
		$spec = $openApiService->generateSpec('partner', '1');

		$this->assertEquals([['bearerAuth' => []]], $spec['security']);
		// The mock controller has apiId: partner for /partner-only
		$this->assertArrayHasKey('security', $spec['paths']['/partner-only']['get']);
		$this->assertEquals([['bearerAuth' => []]], $spec['paths']['/partner-only']['get']['security']);
	}

	public function testGenerateSpecWithPublicAuthMode(): void {
		$apiRegistry = $this->createStub(ApiRegistry::class);
		$apiRegistry->method('getSecurityConfig')->willReturn(['authMode' => 'public']);

		$extensionConfiguration = $this->createStub(ExtensionConfiguration::class);
		$controllers = new \ArrayIterator([new MockOpenApiController()]);
		$discoveryService = $this->getDiscoveryService($controllers);

		$openApiService = $this->getOpenApiService($discoveryService, $apiRegistry, $extensionConfiguration);
		$spec = $openApiService->generateSpec('public', '1');

		// Public API should NOT have any security schemes or global security
		$this->assertArrayNotHasKey('bearerAuth', $spec['components']['securitySchemes'] ?? []);
		$this->assertArrayNotHasKey('cookieAuth', $spec['components']['securitySchemes'] ?? []);
		$this->assertArrayNotHasKey('security', $spec);

		// Check route-level security for /test (MockOpenApiController testAction is registered for public)
		$this->assertArrayHasKey('/test', $spec['paths']);
		$this->assertArrayNotHasKey('security', $spec['paths']['/test']['get']);
	}

	public function testGenerateSpecUsesTcaLabelsForRegularEndpoints(): void {
		$apiRegistry = $this->createStub(ApiRegistry::class);
		$apiRegistry->method('getSecurityConfig')->willReturn(['authMode' => 'public']);

		$extensionConfiguration = $this->createStub(ExtensionConfiguration::class);
		$controllers = new \ArrayIterator([new MockTcaExampleController()]);
		$discoveryService = $this->getDiscoveryService($controllers);

		$GLOBALS['TCA']['tx_test_table'] = [
			'columns' => [
				'title' => [
					'label' => 'LLL:EXT:test/locallang.xlf:title'
				]
			]
		];

		$service = $this->getOpenApiService($discoveryService, $apiRegistry, $extensionConfiguration);
		$spec = $service->generateSpec('public', '1');
		$operation = $spec['paths']['/tca-example']['get'];

		$schema = $operation['responses']['200']['content']['application/json']['schema'];
		$this->assertEquals('Translated Title', $schema['properties']['title']['description']);

		unset($GLOBALS['TCA']['tx_test_table']);
	}

	public function testGenerateSpecUsesRecursiveTcaLabels(): void {
		$apiRegistry = $this->createStub(ApiRegistry::class);
		$apiRegistry->method('getSecurityConfig')->willReturn(['authMode' => 'public']);

		$extensionConfiguration = $this->createStub(ExtensionConfiguration::class);
		$controllers = new \ArrayIterator([new MockRecursiveTcaController()]);
		$discoveryService = $this->getDiscoveryService($controllers);

		$GLOBALS['TCA']['tx_parent_table'] = [
			'columns' => [
				'child' => [
					'label' => 'Parent Child Label',
					'config' => [
						'type' => 'inline',
						'foreign_table' => 'tx_child_table'
					]
				]
			]
		];
		$GLOBALS['TCA']['tx_child_table'] = [
			'columns' => [
				'name' => [
					'label' => 'Child Name Label'
				]
			]
		];

		$service = $this->getOpenApiService($discoveryService, $apiRegistry, $extensionConfiguration);
		$spec = $service->generateSpec('public', '1');
		$operation = $spec['paths']['/recursive-tca']['get'];

		$schema = $operation['responses']['200']['content']['application/json']['schema'];
		$this->assertEquals('Parent Child Label', $schema['properties']['child']['description']);
		$this->assertEquals('Child Name Label', $schema['properties']['child']['properties']['name']['description']);

		unset($GLOBALS['TCA']['tx_parent_table'], $GLOBALS['TCA']['tx_child_table']);
	}

	public function testGenerateSpecFixesMissingDescriptionAndMissingArrayItems(): void {
		$apiRegistry = $this->createStub(ApiRegistry::class);
		$apiRegistry->method('getSecurityConfig')->willReturn(['authMode' => 'public']);

		$extensionConfiguration = $this->createStub(ExtensionConfiguration::class);
		$controllers = new \ArrayIterator([new MockSchemaErrorController()]);
		$discoveryService = $this->getDiscoveryService($controllers);

		$service = $this->getOpenApiService($discoveryService, $apiRegistry, $extensionConfiguration);
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
		$this->assertEquals([['type' => 'string'], ['type' => 'integer']], $properties['tags']['items']['anyOf']);
	}

	public function testGenerateSpecUsesTcaLabelsForEnvelopedRegularEndpoints(): void {
		$apiRegistry = $this->createStub(ApiRegistry::class);
		$apiRegistry->method('getSecurityConfig')->willReturn(['authMode' => 'public']);

		$extensionConfiguration = $this->createStub(ExtensionConfiguration::class);
		$controllers = new \ArrayIterator([new MockEnvelopedTcaExampleController()]);
		$discoveryService = $this->getDiscoveryService($controllers);

		$GLOBALS['TCA']['tx_test_table'] = [
			'columns' => [
				'title' => [
					'label' => 'LLL:EXT:test/locallang.xlf:title'
				]
			]
		];

		$service = $this->getOpenApiService($discoveryService, $apiRegistry, $extensionConfiguration);
		$spec = $service->generateSpec('public', '1');
		$operation = $spec['paths']['/enveloped-tca-example']['get'];

		$schema = $operation['responses']['200']['content']['application/json']['schema'];
		// Current implementation will likely fail to find the label because it's inside 'data'
		$this->assertEquals(
			'Translated Title',
			$schema['properties']['data']['items']['properties']['title']['description']
		);

		unset($GLOBALS['TCA']['tx_test_table']);
	}
	protected function getDiscoveryService(
		iterable $controllers,
		?ApiRegistry $apiRegistry = NULL
	): EndpointDiscoveryService {
		$resourceRegistry = $this->createStub(ResourceRegistry::class);
		$resourceRegistry->method('getResources')->willReturn([]);

		$cache = $this->createStub(FrontendInterface::class);
		$cache->method('get')->willReturn(NULL);
		$cacheManager = $this->createStub(CacheManager::class);
		$cacheManager->method('getCache')->with('sg_apicore_discovery')->willReturn($cache);

		$languageService = $this->createStub(\TYPO3\CMS\Core\Localization\LanguageService::class);
		$languageService->method('sL')->willReturnCallback(function ($key) {
			if ($key === 'LLL:EXT:test/locallang.xlf:title') {
				return 'Translated Title';
			}
			return $key;
		});

		$languageServiceFactory = $this->createStub(LanguageServiceFactory::class);
		$languageServiceFactory->method('create')->with('en')->willReturn($languageService);

		if ($apiRegistry === NULL) {
			$apiRegistry = $this->createStub(ApiRegistry::class);
		}

		return new EndpointDiscoveryService(
			$controllers,
			$resourceRegistry,
			$cacheManager,
			$languageServiceFactory,
			$apiRegistry
		);
	}

	protected function getOpenApiService(
		EndpointDiscoveryService $discoveryService,
		ApiRegistry $apiRegistry,
		ExtensionConfiguration $extensionConfiguration,
		array $globalSchemas = []
	): OpenApiService {
		$cache = $this->createStub(FrontendInterface::class);
		$cache->method('get')->willReturn(NULL);

		$cacheManager = $this->createStub(CacheManager::class);
		$cacheManager->method('getCache')->with('sg_apicore_discovery')->willReturn($cache);

		$schemaRegistry = $this->createStub(SchemaRegistry::class);
		$schemaRegistry->method('getSchemas')->willReturn($globalSchemas);
		$schemaRegistry->method('getSchema')->willReturnCallback(function ($apiId, $schemaName) use ($globalSchemas) {
			return $globalSchemas[$schemaName] ?? NULL;
		});

		return new OpenApiService(
			$discoveryService,
			$apiRegistry,
			$schemaRegistry,
			$extensionConfiguration,
			$cacheManager
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
	#[ApiRoute(path: '/hybrid', methods: ['GET'], apiId: ['public', 'partner'], version: '1', authMode: [
		'user',
		'public'
	])]
	public function hybridAction(): void {
	}
}

/**
 * Mock controller for user-only auth
 */
class MockUserOnlyController {
	#[ApiRoute(path: '/user-only', methods: ['GET'], apiId: 'partner', version: '1', authMode: 'user')]
	public function userAction(): void {
	}
}

/**
 * Mock controller for backend-only auth
 */
class MockBackendOnlyController {
	#[ApiRoute(path: '/backend-only', methods: ['GET'], apiId: 'backend', version: '1', authMode: 'backend')]
	public function backendAction(): void {
	}
}

/**
 * Mock controller for default auth (inherited)
 */
class MockDefaultController {
	#[ApiRoute(path: '/default', methods: ['GET'], apiId: ['public', 'partner', 'backend'], version: '1')]
	public function defaultAction(): void {
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

/**
 * Mock controller for TCA examples
 */
class MockTcaExampleController {
	#[ApiRoute(path: '/tca-example', methods: ['GET'])]
	#[ApiResponse(status: 200, schema: 'tx_test_table', example: ['title' => 'Test'])]
	public function tcaAction(): void {
	}
}

/**
 * Mock controller for enveloped TCA examples
 */
class MockEnvelopedTcaExampleController {
	#[ApiRoute(path: '/enveloped-tca-example', methods: ['GET'])]
	#[ApiResponse(status: 200, schema: 'tx_test_table', example: [
		'data' => [
			['title' => 'Test']
		]
	])]
	public function tcaAction(): void {
	}
}

class MockRecursiveTcaController {
	#[ApiRoute(path: '/recursive-tca', methods: ['GET'])]
	#[ApiResponse(status: 200, schema: 'tx_parent_table', example: ['child' => ['name' => 'Test']])]
	public function recursiveAction(): void {
	}
}

/**
 * Mock controller for complex examples
 */
class MockComplexExampleController {
	#[ApiRoute(path: '/complex', methods: ['GET'])]
	#[ApiResponse(status: 200, example: [
		'title' => 'Complex',
		'count' => 5,
		'items' => [
			['id' => 1]
		]
	])]
	public function complexAction(): void {
	}
}

class MockGlobalSchemaController {
	#[ApiRoute(path: '/global', methods: ['GET'])]
	#[ApiResponse(status: 200, schema: 'GlobalObject')]
	public function globalAction(): void {
	}
}

class MockTcaEnrichmentController {
	#[ApiRoute(path: '/tca', methods: ['GET'])]
	#[ApiResponse(status: 200, schema: 'tx_test_table', example: ['title' => 'Test'])]
	public function tcaAction(): void {
	}
}
