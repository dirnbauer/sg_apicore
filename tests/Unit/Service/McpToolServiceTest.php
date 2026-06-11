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
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SGalinski\SgApiCore\Attribute\ApiBodyParam;
use SGalinski\SgApiCore\Attribute\ApiMcp;
use SGalinski\SgApiCore\Attribute\ApiPathParam;
use SGalinski\SgApiCore\Attribute\ApiQueryParam;
use SGalinski\SgApiCore\Attribute\ApiRoute;
use SGalinski\SgApiCore\Attribute\RequireFullTypoScript;
use SGalinski\SgApiCore\Attribute\RequireScopes;
use SGalinski\SgApiCore\Configuration\ExtensionConfiguration;
use SGalinski\SgApiCore\Security\AuthContext;
use SGalinski\SgApiCore\Service\ApiRegistry;
use SGalinski\SgApiCore\Service\ApiTypoScriptSetupService;
use SGalinski\SgApiCore\Service\EndpointDiscoveryService;
use SGalinski\SgApiCore\Service\EndpointExecutionGuardService;
use SGalinski\SgApiCore\Service\LogService;
use SGalinski\SgApiCore\Service\McpToolService;
use SGalinski\SgApiCore\Service\RateLimitService;
use SGalinski\SgApiCore\Service\RequestValidator;
use SGalinski\SgApiCore\Service\ResourceRegistry;
use SGalinski\SgApiCore\Service\ResponseService;
use SGalinski\SgApiCore\Service\Router;
use stdClass;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

class McpToolServiceTest extends UnitTestCase {
	public function testListToolsBuildsToolsAndSkipsExcludedEndpoints(): void {
		$apiRegistry = new ApiRegistry();
		$apiRegistry->registerApi('sgai', ['1'], ['authMode' => 'public']);

		$service = $this->createMcpToolService([McpListMockController::class], $apiRegistry, TRUE, []);

		$tools = $service->listTools('sgai', '1', 'public');
		$toolNames = array_map(static fn (array $tool): string => $tool['name'], $tools);

		$this->assertContains('sgai_get_credits', $toolNames);
		$this->assertContains('sgai_custom_tool', $toolNames);
		$this->assertNotContains('sgai_get_demo_hidden', $toolNames);
		$this->assertNotContains('sgai_get_hidden', $toolNames);
	}

	public function testListToolsEncodesEmptyPropertiesAsJsonObject(): void {
		$apiRegistry = new ApiRegistry();
		$apiRegistry->registerApi('sgai', ['1'], ['authMode' => 'public']);

		$service = $this->createMcpToolService([McpListMockController::class], $apiRegistry, TRUE, []);

		$tools = $service->listTools('sgai', '1', 'public');
		$creditsTool = array_values(array_filter(
			$tools,
			static fn (array $tool): bool => ($tool['name'] ?? '') === 'sgai_get_credits'
		))[0] ?? NULL;

		$this->assertIsArray($creditsTool);
		$encodedTool = json_encode($creditsTool);
		$this->assertIsString($encodedTool);
		$this->assertStringContainsString('"properties":{}', $encodedTool);
	}

	public function testListToolsEncodesAllPropertiesAsJsonObjects(): void {
		$apiRegistry = new ApiRegistry();
		$apiRegistry->registerApi('sgai', ['1'], ['authMode' => 'public']);

		$service = $this->createMcpToolService(
			[
				McpListMockController::class,
				McpCallMockController::class,
				McpBodyMockController::class,
				McpQueryMockController::class,
			],
			$apiRegistry,
			TRUE,
			[]
		);

		$tools = $service->listTools('sgai', '1', 'public');
		foreach ($tools as $tool) {
			$properties = $tool['inputSchema']['properties'] ?? NULL;
			if ($properties instanceof stdClass) {
				continue;
			}

			$this->assertIsArray($properties);
			$this->assertFalse(
				array_is_list($properties),
				'Tool properties must be an object map for ' . ($tool['name'] ?? 'unknown')
			);
		}
	}

	public function testListToolsReturnsEmptyWhenMcpDisabled(): void {
		$apiRegistry = new ApiRegistry();
		$apiRegistry->registerApi('sgai', ['1'], ['authMode' => 'public']);

		$service = $this->createMcpToolService([McpListMockController::class], $apiRegistry, FALSE, []);

		$this->assertSame([], $service->listTools('sgai', '1', 'public'));
	}

	public function testIsMcpAvailableForApiAppliesGlobalApiDenyList(): void {
		$apiRegistry = new ApiRegistry();
		$apiRegistry->registerApi('sgai', ['1'], ['authMode' => 'public']);

		$service = $this->createMcpToolService([McpListMockController::class], $apiRegistry, TRUE, [], FALSE, NULL, ['sgai']);

		$this->assertFalse($service->isMcpAvailableForApi('sgai'));
		$this->assertSame([], $service->listTools('sgai', '1', 'public'));
	}

	public function testIsMcpAvailableForApiAppliesApiRegistryDisableFlag(): void {
		$apiRegistry = new ApiRegistry();
		$apiRegistry->registerApi('sgai', ['1'], ['authMode' => 'public'], NULL, ['mcpEnabled' => FALSE]);

		$service = $this->createMcpToolService([McpListMockController::class], $apiRegistry, TRUE, []);

		$this->assertFalse($service->isMcpAvailableForApi('sgai'));
		$this->assertSame([], $service->listTools('sgai', '1', 'public'));
	}

	public function testListToolsFiltersEndpointsByAuthContextScopes(): void {
		$apiRegistry = new ApiRegistry();
		$apiRegistry->registerApi('sgai', ['1'], ['authMode' => 'token']);

		$service = $this->createMcpToolService([McpScopedMockController::class], $apiRegistry, TRUE, []);

		$limitedTools = $service->listTools('sgai', '1', 'token', '', new AuthContext('sgai', '', 1, ['read']));
		$this->assertSame(['sgai_get_public'], array_column($limitedTools, 'name'));

		$allowedTools = $service->listTools('sgai', '1', 'token', '', new AuthContext('sgai', '', 1, ['read', 'write']));
		$this->assertSame(['sgai_get_public', 'sgai_post_write'], array_column($allowedTools, 'name'));
	}

	public function testListToolsHidesScopedEndpointsWithoutAuthContext(): void {
		$apiRegistry = new ApiRegistry();
		$apiRegistry->registerApi('sgai', ['1'], ['authMode' => 'token']);

		$service = $this->createMcpToolService([McpScopedMockController::class], $apiRegistry, TRUE, []);

		$tools = $service->listTools('sgai', '1', 'token', '', NULL);

		$this->assertSame(['sgai_get_public'], array_column($tools, 'name'));
	}

	public function testListToolsCreatesToolEntriesForAllConfiguredHttpMethods(): void {
		$apiRegistry = new ApiRegistry();
		$apiRegistry->registerApi('sgai', ['1'], ['authMode' => 'public']);

		$service = $this->createMcpToolService([McpMultiMethodMockController::class], $apiRegistry, TRUE, []);
		$toolNames = array_column($service->listTools('sgai', '1', 'public'), 'name');
		sort($toolNames);

		$this->assertSame(['sgai_get_duplex', 'sgai_post_duplex'], $toolNames);
	}

	public function testCallToolUsesHttpMethodFromResolvedToolEntry(): void {
		$apiRegistry = new ApiRegistry();
		$apiRegistry->registerApi('sgai', ['1'], ['authMode' => 'public']);

		$service = $this->createMcpToolService([McpMultiMethodMockController::class], $apiRegistry, TRUE, []);
		$request = new ServerRequest('https://example.com/api/sgai/v1/mcp', 'POST');

		$getResult = $service->callTool($request, 'sgai', '1', 'sgai_get_duplex', []);
		$postResult = $service->callTool($request, 'sgai', '1', 'sgai_post_duplex', []);

		$this->assertIsArray($getResult);
		$this->assertIsArray($postResult);
		$this->assertSame('GET', $getResult['structuredContent']['method'] ?? NULL);
		$this->assertSame('POST', $postResult['structuredContent']['method'] ?? NULL);
	}

	public function testCallToolInitializesTypoScriptWhenEndpointRequiresFullTypoScript(): void {
		$apiRegistry = new ApiRegistry();
		$apiRegistry->registerApi('sgai', ['1'], ['authMode' => 'public']);

		$apiTypoScriptSetupService = $this->createMock(ApiTypoScriptSetupService::class);
		$apiTypoScriptSetupService->expects($this->once())
			->method('ensureTypoScript')
			->willReturnCallback(static fn (ServerRequestInterface $request): ServerRequestInterface => $request);

		$service = $this->createMcpToolService(
			[McpTypoScriptMockController::class],
			$apiRegistry,
			TRUE,
			[],
			FALSE,
			NULL,
			[],
			$apiTypoScriptSetupService
		);
		$request = new ServerRequest('https://example.com/api/sgai/v1/mcp', 'POST');

		$result = $service->callTool($request, 'sgai', '1', 'sgai_get_need_tsfe', []);

		$this->assertIsArray($result);
		$this->assertFalse($result['isError']);
		$this->assertTrue($result['structuredContent']['ok']);
	}

	public function testListToolsAppliesExactDenylistEntries(): void {
		$apiRegistry = new ApiRegistry();
		$apiRegistry->registerApi('sgai', ['1'], ['authMode' => 'public']);

		$service = $this->createMcpToolService([McpListMockController::class], $apiRegistry, TRUE, ['sgai_get_credits']);
		$toolNames = array_column($service->listTools('sgai', '1', 'public'), 'name');

		$this->assertNotContains('sgai_get_credits', $toolNames);
		$this->assertContains('sgai_custom_tool', $toolNames);
	}

	public function testListToolsAppliesWildcardDenylistEntries(): void {
		$apiRegistry = new ApiRegistry();
		$apiRegistry->registerApi('sgai', ['1'], ['authMode' => 'public']);

		$service = $this->createMcpToolService([McpListMockController::class], $apiRegistry, TRUE, ['sgai_custom_*']);
		$toolNames = array_column($service->listTools('sgai', '1', 'public'), 'name');

		$this->assertContains('sgai_get_credits', $toolNames);
		$this->assertNotContains('sgai_custom_tool', $toolNames);
	}

	public function testCallToolReturnsValidationErrorForMissingPathParam(): void {
		$apiRegistry = new ApiRegistry();
		$apiRegistry->registerApi('sgai', ['1'], ['authMode' => 'public']);

		$service = $this->createMcpToolService([McpCallMockController::class], $apiRegistry, TRUE, []);
		$request = new ServerRequest('https://example.com/api/sgai/v1/mcp', 'POST');

		$result = $service->callTool($request, 'sgai', '1', 'sgai_get_messages_by_messageid', []);

		$this->assertIsArray($result);
		$this->assertTrue($result['isError']);
		$this->assertSame('validation_error', $result['structuredContent']['errorCategory']);
	}

	public function testCallToolDispatchesEndpointAndReturnsStructuredSuccess(): void {
		$apiRegistry = new ApiRegistry();
		$apiRegistry->registerApi('sgai', ['1'], ['authMode' => 'public']);

		$service = $this->createMcpToolService([McpCallMockController::class], $apiRegistry, TRUE, []);
		$request = new ServerRequest('https://example.com/api/sgai/v1/mcp', 'POST');

		$result = $service->callTool($request, 'sgai', '1', 'sgai_get_ping', []);

		$this->assertIsArray($result);
		$this->assertFalse($result['isError']);
		$this->assertTrue($result['structuredContent']['ok']);
		$this->assertStringContainsString('"ok": true', $result['content'][0]['text'] ?? '');
	}

	public function testCallToolReturnsStructuredPayloadAsVisibleTextContent(): void {
		$apiRegistry = new ApiRegistry();
		$apiRegistry->registerApi('sgai', ['1'], ['authMode' => 'public']);

		$service = $this->createMcpToolService([McpCreditsMockController::class], $apiRegistry, TRUE, []);
		$request = new ServerRequest('https://example.com/api/sgai/v1/mcp', 'POST');

		$result = $service->callTool($request, 'sgai', '1', 'sgai_get_credits', []);

		$this->assertIsArray($result);
		$this->assertFalse($result['isError']);
		$this->assertSame(144, $result['structuredContent']['credits']);
		$this->assertStringContainsString('"credits": 144', $result['content'][0]['text'] ?? '');
		$this->assertStringContainsString('"subscription": "Bronze"', $result['content'][0]['text'] ?? '');
	}

	public function testCallToolKeepsStructuredContentCompleteButTruncatesLargeVisibleText(): void {
		$apiRegistry = new ApiRegistry();
		$apiRegistry->registerApi('sgai', ['1'], ['authMode' => 'public']);

		$service = $this->createMcpToolService([McpLargePayloadMockController::class], $apiRegistry, TRUE, []);
		$request = new ServerRequest('https://example.com/api/sgai/v1/mcp', 'POST');

		$result = $service->callTool($request, 'sgai', '1', 'sgai_get_large_payload', []);

		$this->assertIsArray($result);
		$this->assertFalse($result['isError']);
		$this->assertSame(15000, \strlen($result['structuredContent']['imageData']));
		$this->assertLessThan(13000, \strlen($result['content'][0]['text'] ?? ''));
		$this->assertStringContainsString('[truncated, original length: 15000 bytes]', $result['content'][0]['text'] ?? '');
	}

	public function testCallToolTruncatesLargeRawTextResponses(): void {
		$apiRegistry = new ApiRegistry();
		$apiRegistry->registerApi('sgai', ['1'], ['authMode' => 'public']);

		$service = $this->createMcpToolService([McpRawPayloadMockController::class], $apiRegistry, TRUE, []);
		$request = new ServerRequest('https://example.com/api/sgai/v1/mcp', 'POST');

		$result = $service->callTool($request, 'sgai', '1', 'sgai_get_raw_payload', []);

		$this->assertIsArray($result);
		$this->assertFalse($result['isError']);
		$this->assertSame(15000, \strlen($result['structuredContent']['rawBody']));
		$this->assertLessThan(13000, \strlen($result['content'][0]['text'] ?? ''));
		$this->assertStringContainsString('[truncated, original length: 15000 bytes]', $result['content'][0]['text'] ?? '');
	}

	public function testCallToolForwardsBodyParametersIntoRawJsonBody(): void {
		$apiRegistry = new ApiRegistry();
		$apiRegistry->registerApi('sgai', ['1'], ['authMode' => 'public']);

		$service = $this->createMcpToolService([McpBodyMockController::class], $apiRegistry, TRUE, []);
		$request = new ServerRequest('https://example.com/api/sgai/v1/mcp', 'POST');

		$result = $service->callTool(
			$request,
			'sgai',
			'1',
			'sgai_post_seo_title',
			['context' => '<h1>Hello</h1>', 'language' => 'en']
		);

		$this->assertIsArray($result);
		$this->assertFalse($result['isError']);
		$this->assertSame('<h1>Hello</h1>', $result['structuredContent']['parsed']['context'] ?? NULL);
		$rawPayload = json_decode((string) ($result['structuredContent']['raw'] ?? ''), TRUE);
		$this->assertIsArray($rawPayload);
		$this->assertSame('<h1>Hello</h1>', $rawPayload['context'] ?? NULL);
	}

	public function testCallToolForwardsQueryParametersIntoRequestQueryParams(): void {
		$apiRegistry = new ApiRegistry();
		$apiRegistry->registerApi('sgai', ['1'], ['authMode' => 'public']);

		$service = $this->createMcpToolService([McpQueryMockController::class], $apiRegistry, TRUE, []);
		$request = new ServerRequest('https://example.com/api/sgai/v1/mcp', 'POST');

		$result = $service->callTool($request, 'sgai', '1', 'sgai_get_search', ['term' => 'TYPO3', 'page' => 2]);

		$this->assertIsArray($result);
		$this->assertFalse($result['isError']);
		$this->assertSame('TYPO3', $result['structuredContent']['query']['term'] ?? NULL);
		$this->assertSame(2, $result['structuredContent']['query']['page'] ?? NULL);
	}

	public function testCallToolMarksInternalRequestAsMcpExecutionContext(): void {
		$apiRegistry = new ApiRegistry();
		$apiRegistry->registerApi('sgai', ['1'], ['authMode' => 'public']);

		$service = $this->createMcpToolService([McpContextMockController::class], $apiRegistry, TRUE, []);
		$request = new ServerRequest('https://example.com/api/sgai/v1/mcp', 'POST');

		$result = $service->callTool($request, 'sgai', '1', 'sgai_get_context', []);

		$this->assertIsArray($result);
		$this->assertFalse($result['isError']);
		$this->assertSame('mcp', $result['structuredContent']['executionContext'] ?? NULL);
	}

	public function testCallToolAppliesTargetEndpointRateLimit(): void {
		$apiRegistry = new ApiRegistry();
		$apiRegistry->registerApi('sgai', ['1'], ['authMode' => 'public'], NULL, [
			'rateLimit' => [
				'limit' => 1,
				'windowSeconds' => 60,
			],
		]);

		$rateLimitService = $this->createMock(RateLimitService::class);
		$rateLimitService->expects($this->once())->method('consume')->with('sgai:none:ip:unknown', 1, 60, 0)->willReturn([
			'allowed' => TRUE,
			'limit' => 1,
			'remaining' => 0,
			'reset' => time() + 60,
			'burst' => 0,
		]);

		$service = $this->createMcpToolService(
			[McpCallMockController::class],
			$apiRegistry,
			TRUE,
			[],
			TRUE,
			$rateLimitService
		);
		$request = new ServerRequest('https://example.com/api/sgai/v1/mcp', 'POST');

		$result = $service->callTool($request, 'sgai', '1', 'sgai_get_ping', []);

		$this->assertIsArray($result);
		$this->assertFalse($result['isError']);
		$this->assertTrue($result['structuredContent']['ok']);
	}

	/**
	 * @param array $controllerClasses
	 * @param ApiRegistry $apiRegistry
	 * @param bool $mcpEnabled
	 * @param array $denylist
	 * @return McpToolService
	 */
	protected function createMcpToolService(
		array $controllerClasses,
		ApiRegistry $apiRegistry,
		bool $mcpEnabled,
		array $denylist,
		bool $rateLimitEnabled = FALSE,
		?RateLimitService $rateLimitService = NULL,
		array $mcpDisabledApis = [],
		?ApiTypoScriptSetupService $apiTypoScriptSetupService = NULL
	): McpToolService {
		$instances = [];
		foreach ($controllerClasses as $controllerClass) {
			$instances[] = new $controllerClass();
		}
		$controllers = new ArrayIterator($instances);

		$resourceRegistry = $this->createStub(ResourceRegistry::class);
		$resourceRegistry->method('getResources')->willReturn([]);

		$cache = $this->createStub(FrontendInterface::class);
		$cache->method('get')->willReturn(NULL);
		$cacheManager = $this->createStub(CacheManager::class);
		$cacheManager->method('getCache')->with('sg_apicore_discovery')->willReturn($cache);

		$languageServiceFactory = $this->createStub(LanguageServiceFactory::class);
		$endpointDiscoveryService = new EndpointDiscoveryService(
			$controllers,
			$resourceRegistry,
			$cacheManager,
			$languageServiceFactory,
			$apiRegistry
		);

		$responseService = $this->createStub(ResponseService::class);
		$responseService->method('createErrorResponse')->willReturnCallback(function ($title, $detail, $status) {
			return new JsonResponse(['title' => $title, 'detail' => $detail], $status);
		});

		$router = new Router($controllers, $endpointDiscoveryService, new RequestValidator(), $responseService);

		$extensionConfiguration = $this->createStub(ExtensionConfiguration::class);
		$extensionConfiguration->method('isMcpEnabled')->willReturn($mcpEnabled);
		$extensionConfiguration->method('getMcpDisabledApis')->willReturn($mcpDisabledApis);
		$extensionConfiguration->method('getMcpDenylist')->willReturn($denylist);
		$extensionConfiguration->method('getApiPathPrefix')->willReturn('/api/');
		$extensionConfiguration->method('isRateLimitEnabled')->willReturn($rateLimitEnabled);
		$extensionConfiguration->method('getRateLimitDefaultLimit')->willReturn(60);
		$extensionConfiguration->method('getRateLimitWindowSeconds')->willReturn(60);
		$extensionConfiguration->method('getRateLimitDefaultBurst')->willReturn(0);

		$rateLimitService ??= $this->createStub(RateLimitService::class);
		$logService = $this->createStub(LogService::class);
		$endpointExecutionGuardService = new EndpointExecutionGuardService(
			$extensionConfiguration,
			$apiRegistry,
			$resourceRegistry,
			$rateLimitService,
			$responseService,
			$logService
		);

		$apiTypoScriptSetupService ??= $this->createStub(ApiTypoScriptSetupService::class);

		return new McpToolService(
			$endpointDiscoveryService,
			$apiRegistry,
			$extensionConfiguration,
			$router,
			$endpointExecutionGuardService,
			$apiTypoScriptSetupService
		);
	}
}

class McpListMockController {
	#[ApiRoute(path: '/credits', methods: ['GET'], apiId: 'sgai', version: '1')]
	public function creditsAction(ServerRequestInterface $request): ResponseInterface {
		return new JsonResponse(['credits' => 100]);
	}

	#[ApiRoute(path: '/demo/hidden', methods: ['GET'], apiId: 'sgai', version: '1')]
	public function demoHiddenAction(ServerRequestInterface $request): ResponseInterface {
		return new JsonResponse(['hidden' => TRUE]);
	}

	#[ApiRoute(path: '/hidden', methods: ['GET'], apiId: 'sgai', version: '1')]
	#[ApiMcp(exclude: TRUE)]
	public function hiddenAction(ServerRequestInterface $request): ResponseInterface {
		return new JsonResponse(['hidden' => TRUE]);
	}

	#[ApiRoute(path: '/custom', methods: ['GET'], apiId: 'sgai', version: '1')]
	#[ApiMcp(name: 'sgai_custom_tool')]
	public function customAction(ServerRequestInterface $request): ResponseInterface {
		return new JsonResponse(['custom' => TRUE]);
	}
}

class McpContextMockController {
	#[ApiRoute(path: '/context', methods: ['GET'], apiId: 'sgai', version: '1')]
	public function contextAction(ServerRequestInterface $request): ResponseInterface {
		return new JsonResponse([
			'executionContext' => $request->getAttribute('api.executionContext'),
		]);
	}
}

class McpCallMockController {
	#[ApiRoute(path: '/messages/{messageId}', methods: ['GET'], apiId: 'sgai', version: '1')]
	#[ApiPathParam(name: 'messageId', type: 'string')]
	public function messageAction(ServerRequestInterface $request, string $messageId): ResponseInterface {
		return new JsonResponse(['messageId' => $messageId]);
	}

	#[ApiRoute(path: '/ping', methods: ['GET'], apiId: 'sgai', version: '1')]
	public function pingAction(ServerRequestInterface $request): ResponseInterface {
		return new JsonResponse(['ok' => TRUE]);
	}
}

class McpCreditsMockController {
	#[ApiRoute(path: '/credits', methods: ['GET'], apiId: 'sgai', version: '1')]
	public function creditsAction(ServerRequestInterface $request): ResponseInterface {
		return new JsonResponse([
			'credits' => 144,
			'subscription' => 'Bronze',
			'monthly_credits' => 144,
		]);
	}
}

class McpLargePayloadMockController {
	#[ApiRoute(path: '/large-payload', methods: ['GET'], apiId: 'sgai', version: '1')]
	#[ApiMcp(name: 'sgai_get_large_payload')]
	public function largePayloadAction(ServerRequestInterface $request): ResponseInterface {
		return new JsonResponse([
			'imageData' => str_repeat('A', 15000),
			'usage' => ['credits' => 10.0],
		]);
	}
}

class McpRawPayloadMockController {
	#[ApiRoute(path: '/raw-payload', methods: ['GET'], apiId: 'sgai', version: '1')]
	#[ApiMcp(name: 'sgai_get_raw_payload')]
	public function rawPayloadAction(ServerRequestInterface $request): ResponseInterface {
		$response = new Response('php://temp', 200);
		$response->getBody()->write(str_repeat('A', 15000));
		return $response;
	}
}

class McpScopedMockController {
	#[ApiRoute(path: '/public', methods: ['GET'], apiId: 'sgai', version: '1')]
	public function publicAction(ServerRequestInterface $request): ResponseInterface {
		return new JsonResponse(['ok' => TRUE]);
	}

	#[ApiRoute(path: '/write', methods: ['POST'], apiId: 'sgai', version: '1')]
	#[RequireScopes(['write'])]
	public function writeAction(ServerRequestInterface $request): ResponseInterface {
		return new JsonResponse(['ok' => TRUE]);
	}
}

class McpBodyMockController {
	#[ApiRoute(path: '/seo/title', methods: ['POST'], apiId: 'sgai', version: '1')]
	#[ApiBodyParam(name: 'context', type: 'string', required: TRUE)]
	#[ApiBodyParam(name: 'language', type: 'string', required: FALSE)]
	public function seoTitleAction(ServerRequestInterface $request): ResponseInterface {
		return new JsonResponse([
			'parsed' => $request->getParsedBody(),
			'raw' => (string) $request->getBody(),
		]);
	}
}

class McpQueryMockController {
	#[ApiRoute(path: '/search', methods: ['GET'], apiId: 'sgai', version: '1')]
	#[ApiQueryParam(name: 'term', type: 'string', required: TRUE)]
	#[ApiQueryParam(name: 'page', type: 'integer', required: FALSE)]
	public function searchAction(ServerRequestInterface $request): ResponseInterface {
		return new JsonResponse([
			'query' => $request->getQueryParams(),
		]);
	}
}

class McpMultiMethodMockController {
	#[ApiRoute(path: '/duplex', methods: ['GET', 'POST'], apiId: 'sgai', version: '1')]
	public function duplexAction(ServerRequestInterface $request): ResponseInterface {
		return new JsonResponse([
			'method' => $request->getMethod(),
		]);
	}
}

class McpTypoScriptMockController {
	#[ApiRoute(path: '/need-tsfe', methods: ['GET'], apiId: 'sgai', version: '1')]
	#[RequireFullTypoScript]
	public function needTsfeAction(ServerRequestInterface $request): ResponseInterface {
		return new JsonResponse(['ok' => TRUE]);
	}
}
