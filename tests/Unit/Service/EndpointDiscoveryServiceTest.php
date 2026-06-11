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

use ArrayIterator;
use SGalinski\SgApiCore\Attribute\ApiBodyParam;
use SGalinski\SgApiCore\Attribute\ApiEndpoint;
use SGalinski\SgApiCore\Attribute\ApiMcp;
use SGalinski\SgApiCore\Attribute\ApiPathParam;
use SGalinski\SgApiCore\Attribute\ApiQueryParam;
use SGalinski\SgApiCore\Attribute\ApiResponse;
use SGalinski\SgApiCore\Attribute\ApiRoute;
use SGalinski\SgApiCore\Attribute\RequireScopes;
use SGalinski\SgApiCore\Service\ApiRegistry;
use SGalinski\SgApiCore\Service\EndpointDiscoveryService;
use SGalinski\SgApiCore\Service\EndpointFilterInterface;
use SGalinski\SgApiCore\Service\ResourceRegistry;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Test case for EndpointDiscoveryService
 */
class EndpointDiscoveryServiceTest extends UnitTestCase {
	public function testGetAllEndpointsReturnsCompleteData(): void {
		$controllers = new ArrayIterator([new DiscoveryMockController()]);
		$resourceRegistry = $this->createStub(ResourceRegistry::class);
		$resourceRegistry->method('getResources')->willReturn([]);

		$cache = $this->createStub(FrontendInterface::class);
		$cache->method('get')->willReturn(NULL);
		$cacheManager = $this->createStub(CacheManager::class);
		$cacheManager->method('getCache')->with('sg_apicore_discovery')->willReturn($cache);
		$languageServiceFactory = $this->createStub(LanguageServiceFactory::class);
		$apiRegistry = $this->createStub(ApiRegistry::class);

		$service = new EndpointDiscoveryService(
			$controllers,
			$resourceRegistry,
			$cacheManager,
			$languageServiceFactory,
			$apiRegistry
		);
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

	public function testDiscoverySignatureChangesOnResourceChange(): void {
		$controllers = new ArrayIterator([new DiscoveryMockController()]);
		$cache = $this->createStub(FrontendInterface::class);
		$cache->method('get')->willReturn(NULL);
		$cacheManager = $this->createStub(CacheManager::class);
		$cacheManager->method('getCache')->with('sg_apicore_discovery')->willReturn($cache);
		$languageServiceFactory = $this->createStub(LanguageServiceFactory::class);
		$apiRegistry = $this->createStub(ApiRegistry::class);

		$resourceRegistry = new ResourceRegistry();
		$resourceRegistry->registerResource('public', 'tt_content', '/contents', [
			'allowedOperations' => ['list'],
		]);

		$serviceA = new EndpointDiscoveryService(
			$controllers,
			$resourceRegistry,
			$cacheManager,
			$languageServiceFactory,
			$apiRegistry
		);

		$resourceRegistryB = new ResourceRegistry();
		$resourceRegistryB->registerResource('public', 'tt_content', '/contents', [
			'allowedOperations' => ['list', 'get'],
		]);

		$serviceB = new EndpointDiscoveryService(
			$controllers,
			$resourceRegistryB,
			$cacheManager,
			$languageServiceFactory,
			$apiRegistry
		);

		$this->assertTrue($serviceA->getDiscoverySignature() !== $serviceB->getDiscoverySignature());
	}

	public function testDiscoverySignatureIsCachedInMemory(): void {
		$controllers = new ArrayIterator([new DiscoveryMockController()]);
		$resourceRegistry = $this->createMock(ResourceRegistry::class);
		$resourceRegistry->expects($this->once())->method('getResources')->willReturn([]);

		$cache = $this->createStub(FrontendInterface::class);
		$cacheManager = $this->createStub(CacheManager::class);
		$cacheManager->method('getCache')->with('sg_apicore_discovery')->willReturn($cache);
		$languageServiceFactory = $this->createStub(LanguageServiceFactory::class);
		$apiRegistry = $this->createStub(ApiRegistry::class);

		$service = new EndpointDiscoveryService(
			$controllers,
			$resourceRegistry,
			$cacheManager,
			$languageServiceFactory,
			$apiRegistry
		);

		$signature = $service->getDiscoverySignature();

		$this->assertSame($signature, $service->getDiscoverySignature());
	}

	public function testDiscoverySignatureChangesOnEndpointFilterChange(): void {
		$controllers = new ArrayIterator([new DiscoveryMockController()]);
		$resourceRegistry = $this->createStub(ResourceRegistry::class);
		$resourceRegistry->method('getResources')->willReturn([]);
		$cache = $this->createStub(FrontendInterface::class);
		$cache->method('get')->willReturn(NULL);
		$cacheManager = $this->createStub(CacheManager::class);
		$cacheManager->method('getCache')->with('sg_apicore_discovery')->willReturn($cache);
		$languageServiceFactory = $this->createStub(LanguageServiceFactory::class);
		$apiRegistry = $this->createStub(ApiRegistry::class);

		$serviceA = new EndpointDiscoveryService(
			$controllers,
			$resourceRegistry,
			$cacheManager,
			$languageServiceFactory,
			$apiRegistry,
			[new KeepAllEndpointFilter()]
		);

		$serviceB = new EndpointDiscoveryService(
			$controllers,
			$resourceRegistry,
			$cacheManager,
			$languageServiceFactory,
			$apiRegistry,
			[new RemoveAllEndpointFilter()]
		);

		$this->assertNotSame($serviceA->getDiscoverySignature(), $serviceB->getDiscoverySignature());
	}

	public function testGenerateResourceEndpointsUsesTcaLabels(): void {
		$controllers = new ArrayIterator([]);
		$resourceRegistry = new ResourceRegistry();
		$resourceRegistry->registerResource('public', 'tx_test', '/tests', [
			'allowedOperations' => ['list'],
			'readFields' => ['title'],
		]);

		$GLOBALS['TCA']['tx_test'] = [
			'columns' => [
				'title' => [
					'label' => 'LLL:EXT:test/Resources/Private/Language/locallang_db.xlf:tx_test.title',
					'config' => ['type' => 'input'],
				],
			],
		];

		$cache = $this->createStub(FrontendInterface::class);
		$cache->method('get')->willReturn(NULL);
		$cacheManager = $this->createStub(CacheManager::class);
		$cacheManager->method('getCache')->with('sg_apicore_discovery')->willReturn($cache);

		$languageService = $this->createStub(LanguageService::class);
		$languageService->method('sL')->with('LLL:EXT:test/Resources/Private/Language/locallang_db.xlf:tx_test.title')
			->willReturn('Translated Title');

		$languageServiceFactory = $this->createStub(LanguageServiceFactory::class);
		$languageServiceFactory->method('create')->with('en')->willReturn($languageService);
		$apiRegistry = $this->createStub(ApiRegistry::class);

		$service = new EndpointDiscoveryService(
			$controllers,
			$resourceRegistry,
			$cacheManager,
			$languageServiceFactory,
			$apiRegistry
		);
		$endpoints = $service->getAllEndpoints();

		$this->assertCount(1, $endpoints);
		$fieldMetadata = $endpoints[0]['resource']['fieldMetadata'];
		$this->assertEquals('Translated Title', $fieldMetadata['title']['description']);

		unset($GLOBALS['TCA']['tx_test']);
	}

	public function testGetAllEndpointsIncludesMcpMetadata(): void {
		$controllers = new ArrayIterator([new DiscoveryMcpController()]);
		$resourceRegistry = $this->createStub(ResourceRegistry::class);
		$resourceRegistry->method('getResources')->willReturn([]);

		$cache = $this->createStub(FrontendInterface::class);
		$cache->method('get')->willReturn(NULL);
		$cacheManager = $this->createStub(CacheManager::class);
		$cacheManager->method('getCache')->with('sg_apicore_discovery')->willReturn($cache);
		$languageServiceFactory = $this->createStub(LanguageServiceFactory::class);
		$apiRegistry = $this->createStub(ApiRegistry::class);

		$service = new EndpointDiscoveryService(
			$controllers,
			$resourceRegistry,
			$cacheManager,
			$languageServiceFactory,
			$apiRegistry
		);
		$endpoints = $service->getAllEndpoints();

		$this->assertCount(1, $endpoints);
		$this->assertInstanceOf(ApiMcp::class, $endpoints[0]['mcp']);
		$this->assertTrue($endpoints[0]['mcp']->exclude);
		$this->assertSame('custom_tool', $endpoints[0]['mcp']->name);
	}

	public function testGenerateResourceEndpointsIncludeVirtualBodyParamsAndOperationDescriptionOverrides(): void {
		$controllers = new ArrayIterator([]);
		$resourceRegistry = new ResourceRegistry();
		$resourceRegistry->registerResource('public', 'tx_test', '/tests', [
			'allowedOperations' => ['create'],
			'writeFields' => ['title'],
			'virtualBodyParams' => [
				[
					'name' => 'position',
					'type' => 'string',
					'description' => 'Position control',
					'pattern' => '^(top|bottom|after)$',
					'example' => 'bottom',
				],
			],
			'operationDescriptions' => [
				'create' => 'Custom create description',
			],
		]);

		$GLOBALS['TCA']['tx_test'] = [
			'columns' => [
				'title' => [
					'label' => 'Title',
					'config' => ['type' => 'input'],
				],
			],
		];

		$cache = $this->createStub(FrontendInterface::class);
		$cache->method('get')->willReturn(NULL);
		$cacheManager = $this->createStub(CacheManager::class);
		$cacheManager->method('getCache')->with('sg_apicore_discovery')->willReturn($cache);
		$languageServiceFactory = $this->createStub(LanguageServiceFactory::class);
		$apiRegistry = $this->createStub(ApiRegistry::class);

		$service = new EndpointDiscoveryService(
			$controllers,
			$resourceRegistry,
			$cacheManager,
			$languageServiceFactory,
			$apiRegistry
		);
		$endpoints = $service->getAllEndpoints();

		$this->assertCount(1, $endpoints);
		$this->assertStringContainsString('Custom create description', $endpoints[0]['description']);
		$this->assertStringContainsString(
			'The request body schema below is the exact writable field set for the current MCP setup.',
			$endpoints[0]['description']
		);
		$this->assertCount(2, $endpoints[0]['bodyParams']);
		$this->assertSame('position', $endpoints[0]['bodyParams'][1]->name);
		$this->assertSame('bottom', $endpoints[0]['bodyParams'][1]->example);

		unset($GLOBALS['TCA']['tx_test']);
	}

	public function testGenerateResourceEndpointsExposeFilterExamplesForListOperations(): void {
		$controllers = new ArrayIterator([]);
		$resourceRegistry = new ResourceRegistry();
		$resourceRegistry->registerResource('public', 'pages', '/pages', [
			'allowedOperations' => ['list'],
			'readFields' => ['uid', 'pid', 'title', 'slug'],
		]);

		$GLOBALS['TCA']['pages'] = [
			'columns' => [
				'title' => [
					'label' => 'Title',
					'config' => ['type' => 'input'],
				],
				'slug' => [
					'label' => 'Slug',
					'config' => ['type' => 'input'],
				],
			],
		];

		$cache = $this->createStub(FrontendInterface::class);
		$cache->method('get')->willReturn(NULL);
		$cacheManager = $this->createStub(CacheManager::class);
		$cacheManager->method('getCache')->with('sg_apicore_discovery')->willReturn($cache);
		$languageServiceFactory = $this->createStub(LanguageServiceFactory::class);
		$apiRegistry = $this->createStub(ApiRegistry::class);

		$service = new EndpointDiscoveryService(
			$controllers,
			$resourceRegistry,
			$cacheManager,
			$languageServiceFactory,
			$apiRegistry
		);
		$endpoints = $service->getAllEndpoints();

		$this->assertCount(1, $endpoints);
		$this->assertStringContainsString(
			'Allowed filter fields include: pid, slug, title, uid.',
			$endpoints[0]['description']
		);
		$this->assertStringContainsString(
			'Example: `{"filter":{"title":"Example title"}}`',
			$endpoints[0]['description']
		);
		$this->assertStringContainsString('This schema is permission-scoped.', $endpoints[0]['description']);
		$this->assertSame('filter', $endpoints[0]['queryParams'][3]->name);
		$this->assertSame(['title' => 'Example title'], $endpoints[0]['queryParams'][3]->example);
		$this->assertStringContainsString(
			'If a field is missing, treat it as not currently exposed',
			$endpoints[0]['queryParams'][3]->description
		);

		unset($GLOBALS['TCA']['pages']);
	}

	public function testGenerateResourceEndpointsExposeBodyExamplesForPageWriteFields(): void {
		$controllers = new ArrayIterator([]);
		$resourceRegistry = new ResourceRegistry();
		$resourceRegistry->registerResource('public', 'pages', '/pages', [
			'allowedOperations' => ['create', 'update'],
			'readFields' => ['uid', 'pid', 'title', 'slug'],
			'writeFields' => ['pid', 'title', 'slug'],
		]);

		$GLOBALS['TCA']['pages'] = [
			'columns' => [
				'title' => [
					'label' => 'Title',
					'config' => ['type' => 'input'],
				],
				'slug' => [
					'label' => 'Slug',
					'config' => ['type' => 'input'],
				],
			],
		];

		$cache = $this->createStub(FrontendInterface::class);
		$cache->method('get')->willReturn(NULL);
		$cacheManager = $this->createStub(CacheManager::class);
		$cacheManager->method('getCache')->with('sg_apicore_discovery')->willReturn($cache);
		$languageServiceFactory = $this->createStub(LanguageServiceFactory::class);
		$apiRegistry = $this->createStub(ApiRegistry::class);

		$service = new EndpointDiscoveryService(
			$controllers,
			$resourceRegistry,
			$cacheManager,
			$languageServiceFactory,
			$apiRegistry
		);
		$endpoints = $service->getAllEndpoints();

		$this->assertCount(2, $endpoints);
		$this->assertSame('Create pages', $endpoints[0]['summary']);
		$this->assertSame(200, $endpoints[0]['responses'][0]->status);
		$this->assertSame(123, $endpoints[0]['bodyParams'][0]->example);
		$this->assertSame('Example page title', $endpoints[0]['bodyParams'][1]->example);
		$this->assertSame('/example-page-title', $endpoints[0]['bodyParams'][2]->example);
		$this->assertSame('Update pages', $endpoints[1]['summary']);
		$this->assertSame('Example page title', $endpoints[1]['bodyParams'][1]->example);
		$this->assertSame('/example-page-title', $endpoints[1]['bodyParams'][2]->example);

		unset($GLOBALS['TCA']['pages']);
	}

	public function testGenerateResourceEndpointsUseStringPathParamForSlugIdField(): void {
		$controllers = new ArrayIterator([]);
		$resourceRegistry = new ResourceRegistry();
		$resourceRegistry->registerResource('public', 'pages', '/pages', [
			'allowedOperations' => ['get', 'update', 'delete'],
			'idField' => 'slug',
			'readFields' => ['title', 'slug'],
			'writeFields' => ['title'],
		]);

		$GLOBALS['TCA']['pages'] = [
			'columns' => [
				'title' => [
					'label' => 'Title',
					'config' => ['type' => 'input'],
				],
				'slug' => [
					'label' => 'Slug',
					'config' => ['type' => 'input'],
				],
			],
		];

		$cache = $this->createStub(FrontendInterface::class);
		$cache->method('get')->willReturn(NULL);
		$cacheManager = $this->createStub(CacheManager::class);
		$cacheManager->method('getCache')->with('sg_apicore_discovery')->willReturn($cache);
		$languageServiceFactory = $this->createStub(LanguageServiceFactory::class);
		$apiRegistry = $this->createStub(ApiRegistry::class);

		$service = new EndpointDiscoveryService(
			$controllers,
			$resourceRegistry,
			$cacheManager,
			$languageServiceFactory,
			$apiRegistry
		);
		$endpoints = $service->getAllEndpoints();

		$this->assertCount(3, $endpoints);
		$this->assertSame('string', $endpoints[0]['pathParams'][0]->type);
		$this->assertSame('The resource identifier from field "slug".', $endpoints[0]['pathParams'][0]->description);
		$this->assertSame('/example-page-title', $endpoints[0]['pathParams'][0]->example);

		unset($GLOBALS['TCA']['pages']);
	}

	public function testGenerateResourceEndpointsUsePidFilterExampleForTtContent(): void {
		$controllers = new ArrayIterator([]);
		$resourceRegistry = new ResourceRegistry();
		$resourceRegistry->registerResource('public', 'tt_content', '/tt_content', [
			'allowedOperations' => ['list'],
			'readFields' => ['uid', 'pid', 'header', 'bodytext', 'colPos'],
		]);

		$GLOBALS['TCA']['tt_content'] = [
			'columns' => [
				'header' => [
					'label' => 'Header',
					'config' => ['type' => 'input'],
				],
				'bodytext' => [
					'label' => 'Text',
					'config' => ['type' => 'text'],
				],
				'colPos' => [
					'label' => 'Columns',
					'config' => ['type' => 'select'],
				],
			],
		];

		$cache = $this->createStub(FrontendInterface::class);
		$cache->method('get')->willReturn(NULL);
		$cacheManager = $this->createStub(CacheManager::class);
		$cacheManager->method('getCache')->with('sg_apicore_discovery')->willReturn($cache);
		$languageServiceFactory = $this->createStub(LanguageServiceFactory::class);
		$apiRegistry = $this->createStub(ApiRegistry::class);

		$service = new EndpointDiscoveryService(
			$controllers,
			$resourceRegistry,
			$cacheManager,
			$languageServiceFactory,
			$apiRegistry
		);
		$endpoints = $service->getAllEndpoints();

		$this->assertCount(1, $endpoints);
		$this->assertStringContainsString('Example: `{"filter":{"pid":123}}`', $endpoints[0]['description']);
		$this->assertSame(['pid' => 123], $endpoints[0]['queryParams'][3]->example);
		$this->assertStringContainsString(
			'If a field is missing, treat it as not currently exposed',
			$endpoints[0]['queryParams'][3]->description
		);

		unset($GLOBALS['TCA']['tt_content']);
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

class DiscoveryMcpController {
	#[ApiRoute(path: '/mcp-test', methods: ['GET'], apiId: 'public', version: '1')]
	#[ApiMcp(exclude: TRUE, name: 'custom_tool')]
	public function testMcpAction(): void {
	}
}

class KeepAllEndpointFilter implements EndpointFilterInterface {
	public function filterEndpoints(array $endpoints): array {
		return $endpoints;
	}
}

class RemoveAllEndpointFilter implements EndpointFilterInterface {
	public function filterEndpoints(array $endpoints): array {
		return [];
	}
}
