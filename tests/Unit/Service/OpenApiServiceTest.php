<?php

namespace SGalinski\SgApiCore\Tests\Unit\Service;

use SGalinski\SgApiCore\Attribute\ApiEndpoint;
use SGalinski\SgApiCore\Attribute\ApiRoute;
use SGalinski\SgApiCore\Configuration\ExtensionConfiguration;
use SGalinski\SgApiCore\Service\ApiRegistry;
use SGalinski\SgApiCore\Service\OpenApiService;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Test case for OpenApiService
 */
class OpenApiServiceTest extends UnitTestCase {
	public function testGenerateSpecContainsPaths(): void {
		$apiRegistry = $this->createStub(ApiRegistry::class);
		$apiRegistry->method('getSecurityConfig')->willReturn(['authMode' => 'public']);

		$extensionConfiguration = $this->createStub(ExtensionConfiguration::class);
		$extensionConfiguration->method('getApiPathPrefix')->willReturn('/api/');

		$controllers = new \ArrayIterator([new MockOpenApiController()]);

		$service = new OpenApiService($controllers, $apiRegistry, $extensionConfiguration);
		$spec = $service->generateSpec('public', '1');

		$this->assertEquals('3.0.3', $spec['openapi']);
		$this->assertArrayHasKey('/test', $spec['paths']);
		$this->assertArrayHasKey('get', $spec['paths']['/test']);
		$this->assertEquals('Test summary', $spec['paths']['/test']['get']['summary']);
	}

	public function testGenerateSpecFiltersByApiId(): void {
		$apiRegistry = $this->createStub(ApiRegistry::class);
		$apiRegistry->method('getSecurityConfig')->willReturn(['authMode' => 'public']);

		$extensionConfiguration = $this->createStub(ExtensionConfiguration::class);

		$controllers = new \ArrayIterator([new MockOpenApiController()]);

		$service = new OpenApiService($controllers, $apiRegistry, $extensionConfiguration);

		// 'public' api should have /test but not /partner-only
		$spec = $service->generateSpec('public', '1');
		$this->assertArrayHasKey('/test', $spec['paths']);
		$this->assertArrayNotHasKey('/partner-only', $spec['paths']);

		// 'partner' api should have /partner-only but not /test
		$specPartner = $service->generateSpec('partner', '1');
		$this->assertArrayHasKey('/partner-only', $specPartner['paths']);
		$this->assertArrayNotHasKey('/test', $specPartner['paths']);
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
