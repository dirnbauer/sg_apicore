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
use SGalinski\SgApiCore\Attribute\RequireScopes;
use SGalinski\SgApiCore\Service\ApiRegistry;
use SGalinski\SgApiCore\Service\EndpointDiscoveryService;
use SGalinski\SgApiCore\Service\ResourceRegistry;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Test case for EndpointDiscoveryService
 */
#[\PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations]
class EndpointDiscoveryServiceTest extends UnitTestCase {
	public function testGetAllEndpointsReturnsCompleteData(): void {
		$controllers = new \ArrayIterator([new DiscoveryMockController()]);
		$resourceRegistry = $this->createMock(ResourceRegistry::class);
		$resourceRegistry->method('getResources')->willReturn([]);

		$cache = $this->createMock(FrontendInterface::class);
		$cache->method('get')->willReturn(NULL);
		$cacheManager = $this->createMock(CacheManager::class);
		$cacheManager->method('getCache')->with('sg_apicore_discovery')->willReturn($cache);
		$languageServiceFactory = $this->createMock(LanguageServiceFactory::class);
		$apiRegistry = $this->createMock(ApiRegistry::class);

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
		$controllers = new \ArrayIterator([new DiscoveryMockController()]);
		$cache = $this->createMock(FrontendInterface::class);
		$cache->method('get')->willReturn(NULL);
		$cacheManager = $this->createMock(CacheManager::class);
		$cacheManager->method('getCache')->with('sg_apicore_discovery')->willReturn($cache);
		$languageServiceFactory = $this->createMock(LanguageServiceFactory::class);
		$apiRegistry = $this->createMock(ApiRegistry::class);

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

	public function testGenerateResourceEndpointsUsesTcaLabels(): void {
		$controllers = new \ArrayIterator([]);
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

		$cache = $this->createMock(FrontendInterface::class);
		$cache->method('get')->willReturn(NULL);
		$cacheManager = $this->createMock(CacheManager::class);
		$cacheManager->method('getCache')->with('sg_apicore_discovery')->willReturn($cache);

		$languageService = $this->createMock(LanguageService::class);
		$languageService->method('sL')->with('LLL:EXT:test/Resources/Private/Language/locallang_db.xlf:tx_test.title')
			->willReturn('Translated Title');

		$languageServiceFactory = $this->createMock(LanguageServiceFactory::class);
		$languageServiceFactory->method('create')->with('en')->willReturn($languageService);
		$apiRegistry = $this->createMock(ApiRegistry::class);

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
