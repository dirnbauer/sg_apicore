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

namespace SGalinski\SgApiCore\Tests\Unit\Controller;

use SGalinski\SgApiCore\Configuration\ExtensionConfiguration;
use SGalinski\SgApiCore\Controller\McpController;
use SGalinski\SgApiCore\Security\AuthContext;
use SGalinski\SgApiCore\Service\McpToolService;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

class McpControllerTest extends UnitTestCase {
	public function testInitializeReturnsCurrentProtocolVersionAndToolCapabilitiesObject(): void {
		$mcpToolService = $this->createStub(McpToolService::class);
		$extensionConfiguration = $this->createStub(ExtensionConfiguration::class);
		$extensionConfiguration->method('isMcpEnabled')->willReturn(TRUE);

		$controller = new McpController($mcpToolService, $extensionConfiguration);
		$request = new ServerRequest('https://example.com/api/sgai/v1/mcp', 'POST');
		$request = $request->withParsedBody([
			'jsonrpc' => '2.0',
			'id' => 1,
			'method' => 'initialize',
			'params' => [
				'protocolVersion' => '2025-06-18',
			],
		]);

		$response = $controller->handleAction($request);
		$body = (string) $response->getBody();
		$payload = json_decode($body, TRUE);

		$this->assertSame('2025-06-18', $payload['result']['protocolVersion']);
		$this->assertStringContainsString('"tools":{}', $body);
	}

	public function testToolsListReturnsJsonRpcResult(): void {
		$mcpToolService = $this->createStub(McpToolService::class);
		$mcpToolService->method('getAuthModeForApi')->willReturn('public');
		$mcpToolService->method('listTools')->willReturn([
			[
				'name' => 'sgai_get_credits',
				'description' => 'Credits',
				'inputSchema' => ['type' => 'object'],
			],
		]);

		$extensionConfiguration = $this->createStub(ExtensionConfiguration::class);
		$extensionConfiguration->method('isMcpEnabled')->willReturn(TRUE);

		$controller = new McpController($mcpToolService, $extensionConfiguration);
		$request = new ServerRequest('https://example.com/api/sgai/v1/mcp', 'POST');
		$request = $request
			->withAttribute('api.id', 'sgai')
			->withAttribute('api.version', '1')
			->withAttribute('api.auth', new AuthContext('sgai', ''))
			->withParsedBody([
				'jsonrpc' => '2.0',
				'id' => 'abc',
				'method' => 'tools/list',
			]);

		$response = $controller->handleAction($request);
		$payload = json_decode((string) $response->getBody(), TRUE);

		$this->assertSame('2.0', $payload['jsonrpc']);
		$this->assertSame('abc', $payload['id']);
		$this->assertSame('sgai_get_credits', $payload['result']['tools'][0]['name']);
	}

	public function testToolsListRequiresAuthentication(): void {
		$mcpToolService = $this->createStub(McpToolService::class);
		$mcpToolService->method('getAuthModeForApi')->willReturn('token');
		$extensionConfiguration = $this->createStub(ExtensionConfiguration::class);
		$extensionConfiguration->method('isMcpEnabled')->willReturn(TRUE);

		$controller = new McpController($mcpToolService, $extensionConfiguration);
		$request = (new ServerRequest('https://example.com/api/sgai/v1/mcp', 'POST'))
			->withAttribute('api.id', 'sgai')
			->withAttribute('api.version', '1')
			->withParsedBody([
				'jsonrpc' => '2.0',
				'id' => 1,
				'method' => 'tools/list',
			]);

		$response = $controller->handleAction($request);
		$payload = json_decode((string) $response->getBody(), TRUE);

		$this->assertSame(-32002, $payload['error']['code']);
	}

	public function testToolsCallRejectsNonObjectArguments(): void {
		$mcpToolService = $this->createMock(McpToolService::class);
		$mcpToolService->method('getAuthModeForApi')->willReturn('public');
		$mcpToolService->expects($this->never())->method('callTool');

		$extensionConfiguration = $this->createStub(ExtensionConfiguration::class);
		$extensionConfiguration->method('isMcpEnabled')->willReturn(TRUE);

		$controller = new McpController($mcpToolService, $extensionConfiguration);
		$request = (new ServerRequest('https://example.com/api/sgai/v1/mcp', 'POST'))
			->withAttribute('api.id', 'sgai')
			->withAttribute('api.version', '1')
			->withAttribute('api.auth', new AuthContext('sgai', ''))
			->withParsedBody([
				'jsonrpc' => '2.0',
				'id' => 'call-1',
				'method' => 'tools/call',
				'params' => [
					'name' => 'sgai_get_credits',
					'arguments' => 'invalid',
				],
			]);

		$response = $controller->handleAction($request);
		$payload = json_decode((string) $response->getBody(), TRUE);

		$this->assertSame(-32602, $payload['error']['code']);
		$this->assertSame('Invalid params: "arguments" must be an object.', $payload['error']['message']);
	}

	public function testToolsCallRejectsListArguments(): void {
		$mcpToolService = $this->createMock(McpToolService::class);
		$mcpToolService->method('getAuthModeForApi')->willReturn('public');
		$mcpToolService->expects($this->never())->method('callTool');

		$extensionConfiguration = $this->createStub(ExtensionConfiguration::class);
		$extensionConfiguration->method('isMcpEnabled')->willReturn(TRUE);

		$controller = new McpController($mcpToolService, $extensionConfiguration);
		$request = (new ServerRequest('https://example.com/api/sgai/v1/mcp', 'POST'))
			->withAttribute('api.id', 'sgai')
			->withAttribute('api.version', '1')
			->withAttribute('api.auth', new AuthContext('sgai', ''))
			->withParsedBody([
				'jsonrpc' => '2.0',
				'id' => 'call-1',
				'method' => 'tools/call',
				'params' => [
					'name' => 'sgai_get_credits',
					'arguments' => ['invalid'],
				],
			]);

		$response = $controller->handleAction($request);
		$payload = json_decode((string) $response->getBody(), TRUE);

		$this->assertSame(-32602, $payload['error']['code']);
		$this->assertSame('Invalid params: "arguments" must be an object.', $payload['error']['message']);
	}

	public function testToolsCallRequiresAuthentication(): void {
		$mcpToolService = $this->createMock(McpToolService::class);
		$mcpToolService->method('getAuthModeForApi')->willReturn('token');
		$mcpToolService->expects($this->never())->method('callTool');

		$extensionConfiguration = $this->createStub(ExtensionConfiguration::class);
		$extensionConfiguration->method('isMcpEnabled')->willReturn(TRUE);

		$controller = new McpController($mcpToolService, $extensionConfiguration);
		$request = (new ServerRequest('https://example.com/api/sgai/v1/mcp', 'POST'))
			->withAttribute('api.id', 'sgai')
			->withAttribute('api.version', '1')
			->withParsedBody([
				'jsonrpc' => '2.0',
				'id' => 1,
				'method' => 'tools/call',
				'params' => [
					'name' => 'sgai_get_credits',
					'arguments' => [],
				],
			]);

		$response = $controller->handleAction($request);
		$payload = json_decode((string) $response->getBody(), TRUE);

		$this->assertSame(-32002, $payload['error']['code']);
	}

	public function testToolsCallReturnsToolNotFoundWhenServiceCannotResolveTool(): void {
		$mcpToolService = $this->createStub(McpToolService::class);
		$mcpToolService->method('getAuthModeForApi')->willReturn('public');
		$mcpToolService->method('callTool')->willReturn(NULL);

		$extensionConfiguration = $this->createStub(ExtensionConfiguration::class);
		$extensionConfiguration->method('isMcpEnabled')->willReturn(TRUE);

		$controller = new McpController($mcpToolService, $extensionConfiguration);
		$request = (new ServerRequest('https://example.com/api/sgai/v1/mcp', 'POST'))
			->withAttribute('api.id', 'sgai')
			->withAttribute('api.version', '1')
			->withAttribute('api.auth', new AuthContext('sgai', ''))
			->withParsedBody([
				'jsonrpc' => '2.0',
				'id' => 1,
				'method' => 'tools/call',
				'params' => [
					'name' => 'sgai_missing_tool',
					'arguments' => [],
				],
			]);

		$response = $controller->handleAction($request);
		$payload = json_decode((string) $response->getBody(), TRUE);

		$this->assertSame(-32001, $payload['error']['code']);
		$this->assertSame('Tool not found: sgai_missing_tool', $payload['error']['message']);
	}

	public function testToolsCallReturnsMethodNotFoundErrorForUnknownMethod(): void {
		$mcpToolService = $this->createStub(McpToolService::class);
		$extensionConfiguration = $this->createStub(ExtensionConfiguration::class);
		$extensionConfiguration->method('isMcpEnabled')->willReturn(TRUE);

		$controller = new McpController($mcpToolService, $extensionConfiguration);
		$request = new ServerRequest('https://example.com/api/sgai/v1/mcp', 'POST');
		$request = $request->withParsedBody([
			'jsonrpc' => '2.0',
			'id' => 1,
			'method' => 'unknown',
		]);

		$response = $controller->handleAction($request);
		$payload = json_decode((string) $response->getBody(), TRUE);

		$this->assertSame(-32601, $payload['error']['code']);
	}

	public function testCompletionCompleteReturnsMethodNotFoundWhenCapabilityIsUnsupported(): void {
		$mcpToolService = $this->createStub(McpToolService::class);
		$mcpToolService->method('getAuthModeForApi')->willReturn('public');
		$extensionConfiguration = $this->createStub(ExtensionConfiguration::class);
		$extensionConfiguration->method('isMcpEnabled')->willReturn(TRUE);

		$controller = new McpController($mcpToolService, $extensionConfiguration);
		$request = new ServerRequest('https://example.com/api/sgai/v1/mcp', 'POST');
		$request = $request
			->withAttribute('api.id', 'sgai')
			->withAttribute('api.version', '1')
			->withParsedBody([
				'jsonrpc' => '2.0',
				'id' => 1,
				'method' => 'completion/complete',
				'params' => [
					'ref' => [
						'type' => 'ref/prompt',
						'name' => 'code_review',
					],
					'argument' => [
						'name' => 'language',
						'value' => 'py',
					],
				],
			]);

		$response = $controller->handleAction($request);
		$payload = json_decode((string) $response->getBody(), TRUE);

		$this->assertSame('2.0', $payload['jsonrpc']);
		$this->assertSame(1, $payload['id']);
		$this->assertSame(-32601, $payload['error']['code']);
	}

	public function testInitializedNotificationReturnsAcceptedWithoutBody(): void {
		$mcpToolService = $this->createStub(McpToolService::class);
		$extensionConfiguration = $this->createStub(ExtensionConfiguration::class);
		$extensionConfiguration->method('isMcpEnabled')->willReturn(TRUE);

		$controller = new McpController($mcpToolService, $extensionConfiguration);
		$request = new ServerRequest('https://example.com/api/sgai/v1/mcp', 'POST');
		$request = $request->withParsedBody([
			'jsonrpc' => '2.0',
			'method' => 'notifications/initialized',
		]);

		$response = $controller->handleAction($request);

		$this->assertSame(202, $response->getStatusCode());
		$this->assertSame('', (string) $response->getBody());
	}

	public function testStreamActionReturnsSseResponseForGetRequests(): void {
		$mcpToolService = $this->createStub(McpToolService::class);
		$mcpToolService->method('isMcpAvailableForApi')->with('sgai')->willReturn(TRUE);
		$extensionConfiguration = $this->createStub(ExtensionConfiguration::class);
		$extensionConfiguration->method('isMcpEnabled')->willReturn(TRUE);

		$controller = new McpController($mcpToolService, $extensionConfiguration);
		$request = (new ServerRequest('https://example.com/api/sgai/v1/mcp', 'GET'))
			->withAttribute('api.id', 'sgai')
			->withHeader('Accept', 'text/event-stream');

		$response = $controller->streamAction($request);

		$this->assertSame(200, $response->getStatusCode());
		$this->assertSame('text/event-stream; charset=utf-8', $response->getHeaderLine('Content-Type'));
		$this->assertSame('', $response->getHeaderLine('Connection'));
		$this->assertStringContainsString('retry: 60000', (string) $response->getBody());
		$this->assertStringContainsString('sg_apicore MCP stream ready', (string) $response->getBody());
	}

	public function testStreamActionReturnsNotFoundWhenMcpIsUnavailableForApi(): void {
		$mcpToolService = $this->createStub(McpToolService::class);
		$mcpToolService->method('isMcpAvailableForApi')->with('sgai')->willReturn(FALSE);
		$extensionConfiguration = $this->createStub(ExtensionConfiguration::class);

		$controller = new McpController($mcpToolService, $extensionConfiguration);
		$request = (new ServerRequest('https://example.com/api/sgai/v1/mcp', 'GET'))
			->withAttribute('api.id', 'sgai')
			->withHeader('Accept', 'text/event-stream');

		$response = $controller->streamAction($request);

		$this->assertSame(404, $response->getStatusCode());
	}

	public function testStreamActionRejectsClientsWithoutSseAcceptHeader(): void {
		$mcpToolService = $this->createStub(McpToolService::class);
		$extensionConfiguration = $this->createStub(ExtensionConfiguration::class);
		$extensionConfiguration->method('isMcpEnabled')->willReturn(TRUE);

		$controller = new McpController($mcpToolService, $extensionConfiguration);
		$request = (new ServerRequest('https://example.com/api/sgai/v1/mcp', 'GET'))
			->withHeader('Accept', 'application/json');

		$response = $controller->streamAction($request);

		$this->assertSame(406, $response->getStatusCode());
	}
}
