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

namespace SGalinski\SgApiCore\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ReflectionException;
use SGalinski\SgApiCore\Attribute\ApiBodyParam;
use SGalinski\SgApiCore\Attribute\ApiEndpoint;
use SGalinski\SgApiCore\Attribute\ApiMcp;
use SGalinski\SgApiCore\Attribute\ApiRoute;
use SGalinski\SgApiCore\Configuration\ExtensionConfiguration;
use SGalinski\SgApiCore\Security\AuthContext;
use SGalinski\SgApiCore\Service\McpToolService;
use stdClass;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\Response;

/**
 * Controller exposing MCP JSON-RPC methods.
 */
class McpController {
	protected const PROTOCOL_VERSION = '2025-06-18';
	protected const COMPAT_TOOL_SEARCH = 'search';
	protected const COMPAT_TOOL_FETCH = 'fetch';

	/**
	 * @param McpToolService $mcpToolService
	 * @param ExtensionConfiguration $extensionConfiguration
	 */
	public function __construct(
		protected McpToolService $mcpToolService,
		protected ExtensionConfiguration $extensionConfiguration
	) {
	}

	/**
	 * Handles MCP JSON-RPC methods over HTTP.
	 *
	 * @param ServerRequestInterface $request
	 * @return ResponseInterface
	 * @throws ReflectionException
	 */
	#[ApiRoute(path: '/mcp', methods: ['POST'], authMode: 'public')]
	#[ApiEndpoint(
		summary: 'MCP JSON-RPC endpoint',
		description: 'Supported methods: initialize, tools/list, tools/call, resources/list, resources/read. '
			. 'For MCP transport details and cURL examples see docs/MCP.md in this extension '
			. 'or https://www.sgalinski.de/en/typo3-products-web-development/modern-api-core-for-typo3/.',
		tags: ['MCP']
	)]
	#[ApiMcp(exclude: TRUE)]
	#[ApiBodyParam(
		name: 'jsonrpc',
		type: 'string',
		required: TRUE,
		description: 'JSON-RPC protocol version',
		example: '2.0'
	)]
	#[ApiBodyParam(name: 'method', type: 'string', required: TRUE, description: 'MCP method name', example: 'tools/list')]
	#[ApiBodyParam(name: 'id', type: 'string', required: FALSE, description: 'JSON-RPC request id')]
	public function handleAction(ServerRequestInterface $request): ResponseInterface {
		if (!$this->extensionConfiguration->isMcpEnabled()) {
			return new JsonResponse($this->createErrorResponse(NULL, -32000, 'MCP is disabled by configuration.'));
		}

		$payload = $request->getParsedBody();
		if (!\is_array($payload)) {
			return new JsonResponse($this->createErrorResponse(NULL, -32600, 'Invalid Request: JSON object expected.'));
		}

		$jsonrpc = (string) ($payload['jsonrpc'] ?? '');
		if ($jsonrpc !== '2.0') {
			return new JsonResponse($this->createErrorResponse(
				$payload['id'] ?? NULL,
				-32600,
				'Invalid Request: jsonrpc must be "2.0".'
			));
		}

		$method = (string) ($payload['method'] ?? '');
		$id = $payload['id'] ?? NULL;
		$params = \is_array($payload['params'] ?? NULL) ? $payload['params'] : [];
		$isNotification = !\array_key_exists('id', $payload);

		// JSON-RPC notifications MUST NOT receive a JSON-RPC response.
		// MCP streamable HTTP expects HTTP 202 Accepted with empty body.
		if ($isNotification) {
			return new Response(NULL, 202, [
				'X-Content-Type-Options' => 'nosniff',
				'X-Frame-Options' => 'DENY',
			]);
		}

		$apiId = (string) $request->getAttribute('api.id');
		$version = (string) ($request->getAttribute('api.version') ?? '1');
		$authMode = $this->mcpToolService->getAuthModeForApi($apiId, $version);
		$authContext = $request->getAttribute('api.auth');
		$tenantContext = $request->getAttribute('api.tenant');
		$tenantId = $tenantContext?->getTenantId() ?? '';

		if ($method === 'initialize') {
			return new JsonResponse($this->createResultResponse($id, [
				'protocolVersion' => self::PROTOCOL_VERSION,
				'serverInfo' => [
					'name' => 'sg_apicore',
					'version' => '1.0.0',
				],
				'capabilities' => [
					'tools' => new stdClass(),
					'resources' => new stdClass(),
				],
			]));
		}

		if ($method === 'tools/list') {
			if ($authMode !== 'public' && !$authContext instanceof AuthContext) {
				return new JsonResponse($this->createErrorResponse($id, -32002, 'Authentication required.'));
			}

			$tools = $this->mcpToolService->listTools(
				$apiId,
				$version,
				$authMode,
				$tenantId,
				$authContext instanceof AuthContext ? $authContext : NULL
			);
			$tools = $this->appendCompatibilityTools($tools);
			return new JsonResponse($this->createResultResponse($id, ['tools' => $tools]));
		}

		if ($method === 'tools/call') {
			if ($authMode !== 'public' && !$authContext instanceof AuthContext) {
				return new JsonResponse($this->createErrorResponse($id, -32002, 'Authentication required.'));
			}

			$toolName = (string) ($params['name'] ?? '');
			if ($toolName === '') {
				return new JsonResponse($this->createErrorResponse($id, -32602, 'Invalid params: "name" is required.'));
			}

			if (\array_key_exists('arguments', $params)) {
				if (!\is_array($params['arguments']) || ($params['arguments'] !== [] && array_is_list($params['arguments']))) {
					return new JsonResponse($this->createErrorResponse(
						$id,
						-32602,
						'Invalid params: "arguments" must be an object.'
					));
				}
			}

			$arguments = \is_array($params['arguments'] ?? NULL) ? $params['arguments'] : [];
			if ($toolName === self::COMPAT_TOOL_SEARCH || $toolName === self::COMPAT_TOOL_FETCH) {
				$compatResult = $this->callCompatibilityTool(
					$request,
					$apiId,
					$version,
					$authMode,
					$tenantId,
					$authContext instanceof AuthContext ? $authContext : NULL,
					$toolName,
					$arguments
				);
				return new JsonResponse($this->createResultResponse($id, $compatResult));
			}
			$result = $this->mcpToolService->callTool($request, $apiId, $version, $toolName, $arguments, $authMode);
			if ($result === NULL) {
				return new JsonResponse($this->createErrorResponse($id, -32001, 'Tool not found: ' . $toolName));
			}
			return new JsonResponse($this->createResultResponse($id, $result));
		}

		if ($method === 'resources/list') {
			if ($authMode !== 'public' && !$authContext instanceof AuthContext) {
				return new JsonResponse($this->createErrorResponse($id, -32002, 'Authentication required.'));
			}

			$resources = $this->buildCompatibilityResources(
				$request,
				$apiId,
				$version,
				$authMode,
				$tenantId,
				$authContext instanceof AuthContext ? $authContext : NULL
			);
			return new JsonResponse($this->createResultResponse($id, [
				'resources' => array_map(static fn (array $resource): array => [
					'uri' => (string) ($resource['uri'] ?? ''),
					'name' => (string) ($resource['name'] ?? ''),
					'title' => (string) ($resource['title'] ?? ''),
					'description' => (string) ($resource['description'] ?? ''),
					'mimeType' => (string) ($resource['mimeType'] ?? 'application/json'),
				], $resources),
			]));
		}

		if ($method === 'resources/read') {
			if ($authMode !== 'public' && !$authContext instanceof AuthContext) {
				return new JsonResponse($this->createErrorResponse($id, -32002, 'Authentication required.'));
			}

			$uri = trim((string) ($params['uri'] ?? ''));
			if ($uri === '') {
				return new JsonResponse($this->createErrorResponse($id, -32602, 'Invalid params: "uri" is required.'));
			}

			$resources = $this->buildCompatibilityResources(
				$request,
				$apiId,
				$version,
				$authMode,
				$tenantId,
				$authContext instanceof AuthContext ? $authContext : NULL
			);
			$resource = NULL;
			foreach ($resources as $entry) {
				if (($entry['uri'] ?? '') === $uri) {
					$resource = $entry;
					break;
				}
			}

			if ($resource === NULL) {
				return new JsonResponse($this->createErrorResponse($id, -32002, 'Resource not found: ' . $uri));
			}

			return new JsonResponse($this->createResultResponse($id, [
				'contents' => [[
					'uri' => (string) $resource['uri'],
					'mimeType' => (string) $resource['mimeType'],
					'text' => (string) json_encode([
						'id' => (string) ($resource['id'] ?? ''),
						'title' => (string) ($resource['title'] ?? ''),
						'text' => (string) ($resource['text'] ?? ''),
						'url' => (string) ($resource['url'] ?? ''),
						'metadata' => $resource['metadata'] ?? [],
					], JSON_PRETTY_PRINT),
				]],
			]));
		}

		return new JsonResponse($this->createErrorResponse($id, -32601, 'Method not found: ' . $method));
	}

	/**
	 * Opens the optional Streamable HTTP server-to-client SSE channel.
	 *
	 * @param ServerRequestInterface $request
	 * @return ResponseInterface
	 */
	#[ApiRoute(path: '/mcp', methods: ['GET'], authMode: 'public')]
	#[ApiEndpoint(
		summary: 'MCP server-to-client event stream',
		description: 'Optional SSE stream for MCP Streamable HTTP. '
			. 'Clients must send Accept: text/event-stream, otherwise the endpoint returns HTTP 406. '
			. 'See docs/MCP.md in this extension or '
			. 'https://www.sgalinski.de/en/typo3-products-web-development/modern-api-core-for-typo3/.',
		tags: ['MCP']
	)]
	#[ApiMcp(exclude: TRUE)]
	public function streamAction(ServerRequestInterface $request): ResponseInterface {
		if (!str_contains(strtolower($request->getHeaderLine('Accept')), 'text/event-stream')) {
			return new Response(NULL, 406, [
				'Cache-Control' => 'no-store',
				'X-Content-Type-Options' => 'nosniff',
				'X-Frame-Options' => 'DENY',
			]);
		}

		$apiId = (string) $request->getAttribute('api.id');
		if (!$this->mcpToolService->isMcpAvailableForApi($apiId)) {
			return new Response(NULL, 404, [
				'Cache-Control' => 'no-store',
				'X-Content-Type-Options' => 'nosniff',
				'X-Frame-Options' => 'DENY',
			]);
		}

		$response = new Response('php://temp', 200, [
			'Content-Type' => 'text/event-stream; charset=utf-8',
			'Cache-Control' => 'no-cache, no-transform',
			'X-Accel-Buffering' => 'no',
			'X-Content-Type-Options' => 'nosniff',
			'X-Frame-Options' => 'DENY',
		]);
		$response->getBody()->write("retry: 60000\n: sg_apicore MCP stream ready\n\n");
		return $response;
	}

	/**
	 * @param mixed $id
	 * @param array $result
	 * @return array
	 */
	protected function createResultResponse(mixed $id, array $result): array {
		return [
			'jsonrpc' => '2.0',
			'id' => $id,
			'result' => $result,
		];
	}

	/**
	 * @param mixed $id
	 * @param int $code
	 * @param string $message
	 * @return array
	 */
	protected function createErrorResponse(mixed $id, int $code, string $message): array {
		return [
			'jsonrpc' => '2.0',
			'id' => $id,
			'error' => [
			'code' => $code,
			'message' => $message,
			],
		];
	}

	/**
	 * @param array $tools
	 * @return array
	 */
	protected function appendCompatibilityTools(array $tools): array {
		$toolNames = array_map(static fn (array $tool): string => (string) ($tool['name'] ?? ''), $tools);
		$compatibilityTools = [];
		if (!\in_array(self::COMPAT_TOOL_SEARCH, $toolNames, TRUE)) {
			$compatibilityTools[] = [
				'name' => self::COMPAT_TOOL_SEARCH,
				'description' => 'Search available MCP resources and tools.',
				'inputSchema' => [
					'type' => 'object',
					'properties' => [
						'query' => ['type' => 'string', 'description' => 'Search query string'],
					],
					'required' => ['query'],
					'additionalProperties' => FALSE,
				],
				'outputSchema' => [
					'type' => 'object',
					'properties' => [
						'results' => [
							'type' => 'array',
							'items' => [
								'type' => 'object',
								'properties' => [
									'id' => ['type' => 'string'],
									'title' => ['type' => 'string'],
									'url' => ['type' => 'string'],
								],
								'required' => ['id', 'title', 'url'],
							],
						],
					],
					'required' => ['results'],
				],
				'annotations' => [
					'readOnlyHint' => TRUE,
					'destructiveHint' => FALSE,
					'openWorldHint' => FALSE,
				],
			];
		}

		if (!\in_array(self::COMPAT_TOOL_FETCH, $toolNames, TRUE)) {
			$compatibilityTools[] = [
				'name' => self::COMPAT_TOOL_FETCH,
				'description' => 'Fetch full MCP resource detail by id or tool name.',
				'inputSchema' => [
					'type' => 'object',
					'properties' => [
						'id' => ['type' => 'string', 'description' => 'Resource id or tool name from search results'],
					],
					'required' => ['id'],
					'additionalProperties' => FALSE,
				],
				'outputSchema' => [
					'type' => 'object',
					'properties' => [
						'id' => ['type' => 'string'],
						'title' => ['type' => 'string'],
						'text' => ['type' => 'string'],
						'url' => ['type' => 'string'],
						'metadata' => ['type' => 'object'],
					],
					'required' => ['id', 'title', 'text', 'url'],
				],
				'annotations' => [
					'readOnlyHint' => TRUE,
					'destructiveHint' => FALSE,
					'openWorldHint' => FALSE,
				],
			];
		}

		return array_merge($compatibilityTools, $tools);
	}

	/**
	 * @param ServerRequestInterface $request
	 * @param string $apiId
	 * @param string $version
	 * @param string $authMode
	 * @param string $tenantId
	 * @param AuthContext|null $authContext
	 * @return array
	 * @throws ReflectionException
	 */
	protected function buildCompatibilityResources(
		ServerRequestInterface $request,
		string $apiId,
		string $version,
		string $authMode,
		string $tenantId,
		?AuthContext $authContext
	): array {
		$resolvedTools = $this->mcpToolService->listResolvedTools($apiId, $version, $authMode, $tenantId, $authContext);
		$apiPathPrefix = '/' . trim($this->extensionConfiguration->getApiPathPrefix(), '/');
		$docsPath = rtrim($apiPathPrefix, '/') . '/' . $apiId . '/v' . $version . '/docs/ui';
		$docsUrl = rtrim((string) $request->getUri()->withPath($docsPath), '/');

		return array_map(static fn (array $entry): array => [
			'id' => (string) ($entry['endpointId'] ?? ''),
			'uri' => 'mcp://' . $apiId . '/' . $version . '/' . rawurlencode((string) ($entry['endpointId'] ?? '')),
			'name' => (string) ($entry['tool']['name'] ?? ''),
			'title' => (string) ($entry['tool']['name'] ?? ''),
			'description' => trim(
				(string) ($entry['tool']['description'] ?? '')
				. "\n\n"
				. (string) ($entry['httpMethod'] ?? '')
				. ' '
				. (string) ($entry['path'] ?? '')
			),
			'text' => trim(
				(string) ($entry['tool']['description'] ?? '')
				. "\n\n"
				. (string) ($entry['httpMethod'] ?? '')
				. ' '
				. (string) ($entry['path'] ?? '')
			),
			'url' => $docsUrl,
			'mimeType' => 'application/json',
			'metadata' => [
				'endpointId' => (string) ($entry['endpointId'] ?? ''),
				'toolName' => (string) ($entry['tool']['name'] ?? ''),
				'method' => (string) ($entry['httpMethod'] ?? ''),
				'path' => (string) ($entry['path'] ?? ''),
			],
		], $resolvedTools);
	}

	/**
	 * @param ServerRequestInterface $request
	 * @param string $apiId
	 * @param string $version
	 * @param string $authMode
	 * @param string $tenantId
	 * @param AuthContext|null $authContext
	 * @param string $toolName
	 * @param array $arguments
	 * @return array
	 * @throws ReflectionException
	 */
	protected function callCompatibilityTool(
		ServerRequestInterface $request,
		string $apiId,
		string $version,
		string $authMode,
		string $tenantId,
		?AuthContext $authContext,
		string $toolName,
		array $arguments
	): array {
		$resources = $this->buildCompatibilityResources($request, $apiId, $version, $authMode, $tenantId, $authContext);

		if ($toolName === self::COMPAT_TOOL_SEARCH) {
			$query = strtolower(trim((string) ($arguments['query'] ?? $arguments['q'] ?? '')));
			$limit = max(1, min(50, (int) ($arguments['limit'] ?? 10)));
			$results = [];
			foreach ($resources as $resource) {
				if ($query !== '') {
					$haystack = strtolower(
						(string) ($resource['id'] ?? '')
						. "\n"
						. (string) ($resource['title'] ?? '')
						. "\n"
						. (string) ($resource['text'] ?? '')
					);
					if (!str_contains($haystack, $query)) {
						continue;
					}
				}
				$results[] = $resource;
				if (\count($results) >= $limit) {
					break;
				}
			}

			$structuredContent = ['results' => $results];
			return [
				'isError' => FALSE,
				'content' => [['type' => 'text', 'text' => (string) json_encode($structuredContent, JSON_PRETTY_PRINT)]],
				'structuredContent' => $structuredContent,
			];
		}

		$id = trim((string) ($arguments['id'] ?? ''));
		if ($id === '') {
			return [
				'isError' => TRUE,
				'content' => [['type' => 'text', 'text' => 'Missing required parameter: id']],
				'structuredContent' => ['errorCategory' => 'validation_error', 'message' => 'Missing required parameter: id'],
			];
		}

		$resource = NULL;
		foreach ($resources as $entry) {
			if (($entry['id'] ?? '') === $id || ($entry['title'] ?? '') === $id) {
				$resource = $entry;
				break;
			}
		}
		if ($resource === NULL) {
			return [
				'isError' => TRUE,
				'content' => [['type' => 'text', 'text' => 'Resource not found: ' . $id]],
				'structuredContent' => ['errorCategory' => 'not_found', 'message' => 'Resource not found: ' . $id],
			];
		}

		return [
			'isError' => FALSE,
			'content' => [['type' => 'text', 'text' => (string) json_encode($resource, JSON_PRETTY_PRINT)]],
			'structuredContent' => $resource,
		];
	}
}
