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
use SGalinski\SgApiCore\Attribute\ApiBodyParam;
use SGalinski\SgApiCore\Attribute\ApiEndpoint;
use SGalinski\SgApiCore\Attribute\ApiMcp;
use SGalinski\SgApiCore\Attribute\ApiRoute;
use SGalinski\SgApiCore\Configuration\ExtensionConfiguration;
use SGalinski\SgApiCore\Security\AuthContext;
use SGalinski\SgApiCore\Service\McpToolService;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\Response;

/**
 * Controller exposing MCP JSON-RPC methods.
 */
class McpController {
	protected const PROTOCOL_VERSION = '2025-06-18';

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
	 * @throws \ReflectionException
	 */
	#[ApiRoute(path: '/mcp', methods: ['POST'], authMode: 'public')]
	#[ApiEndpoint(summary: 'MCP JSON-RPC endpoint', tags: ['MCP'])]
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
					'tools' => new \stdClass(),
				],
			]));
		}

		if ($method === 'tools/list') {
			if (!$authContext instanceof AuthContext) {
				return new JsonResponse($this->createErrorResponse($id, -32002, 'Authentication required.'));
			}

			$tools = $this->mcpToolService->listTools($apiId, $version, $authMode, $tenantId, $authContext);
			return new JsonResponse($this->createResultResponse($id, ['tools' => $tools]));
		}

		if ($method === 'tools/call') {
			if (!$authContext instanceof AuthContext) {
				return new JsonResponse($this->createErrorResponse($id, -32002, 'Authentication required.'));
			}

			$toolName = (string) ($params['name'] ?? '');
			if ($toolName === '') {
				return new JsonResponse($this->createErrorResponse($id, -32602, 'Invalid params: "name" is required.'));
			}

			$arguments = \is_array($params['arguments'] ?? NULL) ? $params['arguments'] : [];
			$result = $this->mcpToolService->callTool($request, $apiId, $version, $toolName, $arguments, $authMode);
			if ($result === NULL) {
				return new JsonResponse($this->createErrorResponse($id, -32001, 'Tool not found: ' . $toolName));
			}
			return new JsonResponse($this->createResultResponse($id, $result));
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
	#[ApiEndpoint(summary: 'MCP server-to-client event stream', tags: ['MCP'])]
	#[ApiMcp(exclude: TRUE)]
	public function streamAction(ServerRequestInterface $request): ResponseInterface {
		if (!str_contains(strtolower($request->getHeaderLine('Accept')), 'text/event-stream')) {
			return new Response(NULL, 406, [
				'Cache-Control' => 'no-store',
				'X-Content-Type-Options' => 'nosniff',
				'X-Frame-Options' => 'DENY',
			]);
		}

		if (!$this->extensionConfiguration->isMcpEnabled()) {
			return new Response(NULL, 404, [
				'Cache-Control' => 'no-store',
				'X-Content-Type-Options' => 'nosniff',
				'X-Frame-Options' => 'DENY',
			]);
		}

		$response = new Response('php://temp', 200, [
			'Content-Type' => 'text/event-stream; charset=utf-8',
			'Cache-Control' => 'no-cache, no-transform',
			'Connection' => 'keep-alive',
			'X-Accel-Buffering' => 'no',
			'X-Content-Type-Options' => 'nosniff',
			'X-Frame-Options' => 'DENY',
		]);
		$response->getBody()->write(": sg_apicore MCP stream ready\n\n");
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
}
