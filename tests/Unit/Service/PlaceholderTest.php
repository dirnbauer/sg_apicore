<?php

namespace SGalinski\SgApiCore\Tests\Unit\Service;

use SGalinski\SgApiCore\Attribute\ApiEndpoint;
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

class PlaceholderTest extends UnitTestCase {
	public function testSchemaPlaceholderInExample(): void {
		$schemaRegistry = new SchemaRegistry();
		$schemaRegistry->registerSchema('TestObj', [
			'type' => 'object',
			'properties' => [
				'id' => ['type' => 'integer', 'example' => 123],
				'name' => ['type' => 'string', 'example' => 'Test Name'],
				'tags' => [
					'type' => 'array',
					'items' => ['type' => 'string']
				],
				'media' => [
					'type' => 'array',
					'items' => [
						'type' => 'object',
						'properties' => [
							'url' => ['type' => 'string']
						]
					]
				],
				'meta' => [
					'type' => 'object',
					'properties' => [
						'flag' => ['type' => 'boolean']
					]
				]
			]
		]);

		$discovery = $this->getDiscoveryService([new MockPlaceholderController()]);
		$apiRegistry = $this->createStub(ApiRegistry::class);
		$apiRegistry->method('getSecurityConfig')->willReturn(['authMode' => 'public']);
		$extensionConfiguration = $this->createStub(ExtensionConfiguration::class);

		$cache = $this->createStub(FrontendInterface::class);
		$cacheManager = $this->createStub(CacheManager::class);
		$cacheManager->method('getCache')->willReturn($cache);

		$service = new OpenApiService($discovery, $apiRegistry, $schemaRegistry, $extensionConfiguration, $cacheManager);
		$spec = $service->generateSpec('public', '1');

		$response = $spec['paths']['/placeholder']['get']['responses']['200'];
		$example = $response['content']['application/json']['example'];

		$this->assertEquals(123, $example['data'][0]['id']);
		$this->assertEquals('Test Name', $example['data'][0]['name']);
		$this->assertEquals(['string'], $example['data'][0]['tags']);
		$this->assertEquals('string', $example['data'][0]['media'][0]['url']);
		$this->assertTrue($example['data'][0]['meta']['flag']);
		$this->assertEquals('ok', $example['status']);
	}

	protected function getDiscoveryService(iterable $controllers): EndpointDiscoveryService {
		$resourceRegistry = $this->createStub(ResourceRegistry::class);
		$resourceRegistry->method('getResources')->willReturn([]);
		$cache = $this->createStub(FrontendInterface::class);
		$cacheManager = $this->createStub(CacheManager::class);
		$cacheManager->method('getCache')->willReturn($cache);
		$languageServiceFactory = $this->createStub(LanguageServiceFactory::class);
		return new EndpointDiscoveryService($controllers, $resourceRegistry, $cacheManager, $languageServiceFactory);
	}
}

class MockPlaceholderController {
	#[ApiRoute(path: '/placeholder', methods: ['GET'])]
	#[ApiEndpoint(summary: 'Placeholder test')]
	#[ApiResponse(status: 200, schema: 'TestObj[]', example: [
		'status' => 'ok',
		'data' => 'schema:TestObj[]'
	])]
	public function test(): void {
	}
}
