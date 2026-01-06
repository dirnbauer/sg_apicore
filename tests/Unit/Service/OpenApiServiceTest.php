<?php

namespace SGalinski\SgApiCore\Tests\Unit\Service;

use SGalinski\SgApiCore\Attribute\ApiBodyParam;
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

	public function testGenerateSpecFiltersHybridAuthModeCorrectly(): void {
		$apiRegistry = $this->createStub(ApiRegistry::class);
		$apiRegistry->method('getSecurityConfig')->willReturnMap([
			['public', '1', ['authMode' => 'public']],
			['user', '1', ['authMode' => 'user']],
		]);

		$extensionConfiguration = $this->createStub(ExtensionConfiguration::class);
		$controllers = new \ArrayIterator([new MockHybridController()]);

		$service = new OpenApiService($controllers, $apiRegistry, $extensionConfiguration);

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

		$service = new OpenApiService($controllers, $apiRegistry, $extensionConfiguration);
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
		$this->assertContains('username', $schema['required']);
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
	#[ApiBodyParam(name: 'username', required: TRUE)]
	public function postAction(): void {
	}
}
