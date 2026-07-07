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

namespace SGalinski\SgApiCore\Service;

use Doctrine\DBAL\Exception;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ReflectionException;
use SGalinski\SgApiCore\Attribute\ApiBodyParam;
use SGalinski\SgApiCore\Attribute\ApiMcp;
use SGalinski\SgApiCore\Attribute\ApiPathParam;
use SGalinski\SgApiCore\Attribute\ApiQueryParam;
use SGalinski\SgApiCore\Configuration\ExtensionConfiguration;
use SGalinski\SgApiCore\Context\TenantContext;
use SGalinski\SgApiCore\Security\AuthContext;
use stdClass;
use TYPO3\CMS\Core\Error\Http\AbstractServerErrorException;
use TYPO3\CMS\Core\Http\PropagateResponseException;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\CMS\Core\SingletonInterface;

/**
 * Service for MCP tool discovery and invocation.
 *
 * @phpstan-import-type Endpoint from EndpointDiscoveryService
 * @phpstan-type JsonPayload array<array-key, mixed>
 * @phpstan-type InputSchema array{type: string, properties: array<string, mixed>|stdClass, additionalProperties: false, required?: list<string>}
 * @phpstan-type McpTool array{name: string, description: string, inputSchema: InputSchema}
 * @phpstan-type ResolvedTool array{endpointId: string, apiId: string, version: string, httpMethod: string, path: string, endpoint: Endpoint, tool: McpTool}
 * @phpstan-type ToolResult array{isError?: bool, content: list<array{type: string, text: string}>, structuredContent: JsonPayload}
 * @phpstan-type McpConfig array{exclude: bool, name: string|null, description: string|null, notes: string|null}
 */
class McpToolService implements SingletonInterface {
	protected const TOOL_CONTENT_TEXT_MAX_BYTES = 12000;
	protected const TOOL_CONTENT_TEXT_STRING_MAX_BYTES = 2048;

	/**
	 * @var array<string, list<ResolvedTool>>
	 */
	protected array $resolvedToolsCache = [];

	/**
	 */
	public function __construct(
		protected EndpointDiscoveryService $endpointDiscoveryService,
		protected ApiRegistry $apiRegistry,
		protected ExtensionConfiguration $extensionConfiguration,
		protected Router $router,
		protected EndpointExecutionGuardService $endpointExecutionGuardService,
		protected ApiTypoScriptSetupService $apiTypoScriptSetupService
	) {
	}

	/**
	 * Returns the effective auth mode for an API/version pair.
	 */
	public function getAuthModeForApi(string $apiId, string $version): string {
		$securityConfig = $this->apiRegistry->getSecurityConfig($apiId, $version);
		$authMode = $securityConfig['authMode'] ?? 'token';
		if (\is_array($authMode)) {
			$firstAuthMode = reset($authMode);
			return \is_scalar($firstAuthMode) ? (string) $firstAuthMode : 'token';
		}
		return \is_scalar($authMode) ? (string) $authMode : 'token';
	}

	/**
	 * Returns whether MCP is exposed for the given API.
	 */
	public function isMcpAvailableForApi(string $apiId): bool {
		if (!$this->extensionConfiguration->isMcpEnabled()) {
			return FALSE;
		}

		if (\in_array($apiId, $this->extensionConfiguration->getMcpDisabledApis(), TRUE)) {
			return FALSE;
		}

		return $this->apiRegistry->isMcpEnabledForApi($apiId);
	}

	/**
	 * Lists exposed MCP tools for an API/version pair.
	 *
	 * @return list<McpTool>
	 * @throws ReflectionException
	 */
	public function listTools(
		string $apiId,
		string $version,
		?string $authMode = NULL,
		string $tenantId = '',
		?AuthContext $authContext = NULL
	): array {
		$resolvedTools = $this->listResolvedTools($apiId, $version, $authMode, $tenantId, $authContext);
		$tools = [];
		foreach ($resolvedTools as $entry) {
			$tools[] = $entry['tool'];
		}
		return $tools;
	}

	/**
	 * Lists resolved MCP tools including endpoint metadata for operational use.
	 *
	 * @return list<ResolvedTool>
	 * @throws ReflectionException
	 */
	public function listResolvedTools(
		string $apiId,
		string $version,
		?string $authMode = NULL,
		string $tenantId = '',
		?AuthContext $authContext = NULL
	): array {
		if (!$this->isMcpAvailableForApi($apiId)) {
			return [];
		}

		$effectiveAuthMode = $authMode ?? $this->getAuthModeForApi($apiId, $version);
		$cacheKey = $this->buildResolvedToolsCacheKey($apiId, $version, $effectiveAuthMode, $tenantId, $authContext);
		if (isset($this->resolvedToolsCache[$cacheKey])) {
			return $this->resolvedToolsCache[$cacheKey];
		}

		$endpoints = $this->endpointDiscoveryService->getEndpointsForApi($apiId, $version, $effectiveAuthMode, $tenantId);
		$globalDenylist = $this->normalizeDenylist($this->extensionConfiguration->getMcpDenylist());
		$apiDenylist = $this->normalizeDenylist($this->apiRegistry->getMcpDenylistForApi($apiId));
		$denylist = array_values(array_unique(array_merge($globalDenylist, $apiDenylist)));

		/** @var list<ResolvedTool> $resolved */
		$resolved = [];
		foreach ($endpoints as $endpoint) {
			$mcpConfig = $this->normalizeMcpConfig($endpoint['mcp'] ?? NULL);
			if ($mcpConfig['exclude']) {
				continue;
			}

			if ($this->isConventionExcludedPath((string) ($endpoint['path'] ?? '/'))) {
				continue;
			}

			if (!$this->isEndpointAllowedForAuthContext($endpoint, $authContext)) {
				continue;
			}

			$endpointPath = (string) ($endpoint['path'] ?? '/');
			$httpMethods = array_values(array_unique(array_filter(array_map(
				static fn (mixed $method): string => strtolower(trim((string) $method)),
				(array) ($endpoint['methods'] ?? ['get'])
			), static fn (string $method): bool => $method !== '')));

			foreach ($httpMethods as $httpMethod) {
				$endpointId = $this->buildEndpointId($apiId, $version, $httpMethod, $endpointPath);
				$toolName = $mcpConfig['name'] ?? $this->buildToolName($apiId, $httpMethod, $endpointPath);

				if ($this->matchesDenylist($denylist, [$endpointId, $toolName, $endpointPath, $apiId . ':' . $endpointPath])) {
					continue;
				}

				$description = trim((string) ($mcpConfig['description'] ?? $endpoint['summary'] ?? ''));
				$endpointDescription = trim((string) ($endpoint['description'] ?? ''));
				if ($endpointDescription !== '') {
					$description .= ($description !== '' ? "\n\n" : '') . $endpointDescription;
				}
				if (($mcpConfig['notes'] ?? '') !== '') {
					$description .= ($description !== '' ? "\n\n" : '') . trim((string) $mcpConfig['notes']);
				}

				$resolved[] = [
					'endpointId' => $endpointId,
					'apiId' => $apiId,
					'version' => $version,
					'httpMethod' => strtoupper($httpMethod),
					'path' => $endpointPath,
					'endpoint' => $endpoint,
					'tool' => [
						'name' => $toolName,
						'description' => $description,
						'inputSchema' => $this->buildInputSchema($endpoint),
					],
				];
			}
		}

		$this->resolvedToolsCache[$cacheKey] = $resolved;
		return $resolved;
	}

	/**
	 * Executes an MCP tool by dispatching the mapped API endpoint internally.
	 *
	 * @param array<string, mixed> $arguments
	 * @return ToolResult|null
	 * @throws ReflectionException
	 * @throws Exception
	 * @throws AbstractServerErrorException
	 * @throws PropagateResponseException
	 */
	public function callTool(
		ServerRequestInterface $request,
		string $apiId,
		string $version,
		string $toolName,
		array $arguments = [],
		?string $authMode = NULL
	): ?array {
		$tenantContext = $request->getAttribute('api.tenant');
		$tenantContext = $tenantContext instanceof TenantContext ? $tenantContext : NULL;
		$tenantId = $tenantContext?->getTenantId() ?? '';
		$effectiveAuthMode = $authMode ?? $this->getAuthModeForApi($apiId, $version);
		$authContext = $request->getAttribute('api.auth');
		$resolvedTools = $this->listResolvedTools(
			$apiId,
			$version,
			$effectiveAuthMode,
			$tenantId,
			$authContext instanceof AuthContext ? $authContext : NULL
		);

		$resolvedTool = NULL;
		foreach ($resolvedTools as $entry) {
			if (($entry['tool']['name'] ?? '') === $toolName) {
				$resolvedTool = $entry;
				break;
			}
		}

		if ($resolvedTool === NULL) {
			return NULL;
		}

		$endpoint = $resolvedTool['endpoint'];
		$path = (string) ($endpoint['path'] ?? '/');
		foreach ($endpoint['pathParams'] ?? [] as $pathParam) {
			/** @var ApiPathParam $pathParam */
			if (!\array_key_exists($pathParam->name, $arguments)) {
				return $this->createToolErrorResult(
					'validation_error',
					400,
					'Missing required path parameter: ' . $pathParam->name
				);
			}
			$rawValue = $arguments[$pathParam->name];
			if (!\is_scalar($rawValue)) {
				return $this->createToolErrorResult(
					'validation_error',
					400,
					'Path parameter must be a scalar value: ' . $pathParam->name
				);
			}
			$path = str_replace('{' . $pathParam->name . '}', rawurlencode((string) $rawValue), $path);
		}

		$queryParameters = [];
		foreach ($endpoint['queryParams'] ?? [] as $queryParam) {
			/** @var ApiQueryParam $queryParam */
			if (\array_key_exists($queryParam->name, $arguments)) {
				$queryParameters[$queryParam->name] = $arguments[$queryParam->name];
			}
		}

		$bodyParameters = [];
		foreach ($endpoint['bodyParams'] ?? [] as $bodyParam) {
			/** @var ApiBodyParam $bodyParam */
			if (\array_key_exists($bodyParam->name, $arguments)) {
				$bodyParameters[$bodyParam->name] = $arguments[$bodyParam->name];
			}
		}

		$apiPathPrefix = rtrim($this->extensionConfiguration->getApiPathPrefix(), '/');
		$internalPath = $apiPathPrefix . '/' . $apiId . '/v' . $version . ($path === '/' ? '' : $path);
		if ($internalPath === '') {
			$internalPath = '/';
		}

		$targetUri = $request->getUri()->withPath($internalPath);
		$queryString = http_build_query($queryParameters, '', '&', PHP_QUERY_RFC3986);
		$targetUri = $targetUri->withQuery($queryString);
		$remainingPath = $path !== '/' ? rtrim($path, '/') : $path;
		if ($remainingPath === '') {
			$remainingPath = '/';
		}

		$httpMethod = strtoupper((string) ($resolvedTool['httpMethod'] ?? ($endpoint['methods'][0] ?? 'GET')));
		$internalRequest = $request
			->withMethod($httpMethod)
			->withUri($targetUri)
			->withQueryParams($queryParameters)
			->withAttribute('api.id', $apiId)
			->withAttribute('api.version', $version)
			->withAttribute('api.executionContext', 'mcp')
			->withAttribute('api.remainingPath', $remainingPath)
			->withParsedBody($bodyParameters);

		if (!empty($endpoint['requireFullTypoScript'])) {
			$internalRequest = $internalRequest->withAttribute('api.requireFullTypoScript', TRUE);
			$internalRequest = $this->apiTypoScriptSetupService->ensureTypoScript($internalRequest, $tenantContext);
		}

		if ($bodyParameters !== []) {
			$encodedBody = json_encode($bodyParameters);
			if (\is_string($encodedBody)) {
				$bodyStream = new Stream('php://temp', 'wb+');
				$bodyStream->write($encodedBody);
				$bodyStream->rewind();
				$internalRequest = $internalRequest
					->withBody($bodyStream)
					->withHeader('Content-Type', 'application/json');
			}
		}

		$guardResult = $this->endpointExecutionGuardService->enforceRateLimit($internalRequest);
		if ($guardResult['response'] instanceof ResponseInterface) {
			return $this->normalizeToolResponse($guardResult['response']);
		}

		$internalRequest = $guardResult['request'];
		$response = $this->router->dispatch($internalRequest, $apiId, $version, $remainingPath, $effectiveAuthMode);
		$response = $this->endpointExecutionGuardService->applyRateLimitHeaders($response, $guardResult['rateLimit']);
		return $this->normalizeToolResponse($response);
	}

	/**
	 * @return ToolResult
	 */
	protected function normalizeToolResponse(ResponseInterface $response): array {
		$statusCode = $response->getStatusCode();
		$rawBody = (string) $response->getBody();
		$decodedBody = json_decode($rawBody, TRUE);
		$payload = \is_array($decodedBody) ? $decodedBody : ['rawBody' => $rawBody];

		if ($statusCode >= 200 && $statusCode < 300) {
			return [
				'isError' => FALSE,
				'content' => [
					[
						'type' => 'text',
						'text' => $this->buildToolContentText($payload),
					],
				],
				'structuredContent' => $payload,
			];
		}

		$category = $this->mapStatusToErrorCategory($statusCode);
		$messageValue = $payload['detail'] ?? $payload['message'] ?? 'Tool call failed';
		$message = \is_scalar($messageValue) ? (string) $messageValue : 'Tool call failed';
		return $this->createToolErrorResult($category, $statusCode, $message, $payload);
	}

	/**
	 * @param JsonPayload $payload
	 */
	protected function buildToolContentText(array $payload): string {
		if (\array_key_exists('rawBody', $payload) && \count($payload) === 1) {
			$rawBody = \is_scalar($payload['rawBody']) ? (string) $payload['rawBody'] : '';
			return $rawBody !== ''
				? $this->truncateStringForTextContent($rawBody, self::TOOL_CONTENT_TEXT_MAX_BYTES)
				: 'Tool executed successfully.';
		}

		$encodedPayload = json_encode(
			$this->preparePayloadForTextContent($payload),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);
		if (!\is_string($encodedPayload)) {
			return 'Tool executed successfully.';
		}

		return $this->truncateStringForTextContent($encodedPayload, self::TOOL_CONTENT_TEXT_MAX_BYTES);
	}

	/**
	 * @param JsonPayload $payload
	 * @return JsonPayload
	 */
	protected function preparePayloadForTextContent(array $payload): array {
		$prepared = [];
		foreach ($payload as $key => $value) {
			if (\is_array($value)) {
				$prepared[$key] = $this->preparePayloadForTextContent($value);
				continue;
			}

			if (\is_string($value)) {
				$prepared[$key] = $this->truncateStringForTextContent($value, self::TOOL_CONTENT_TEXT_STRING_MAX_BYTES);
				continue;
			}

			$prepared[$key] = $value;
		}

		return $prepared;
	}

	/**
	 */
	protected function truncateStringForTextContent(string $value, int $maxBytes): string {
		if (\strlen($value) <= $maxBytes) {
			return $value;
		}

		return substr($value, 0, $maxBytes) . "\n[truncated, original length: " . \strlen($value) . ' bytes]';
	}

	/**
	 * @param Endpoint $endpoint
	 * @return InputSchema
	 */
	protected function buildInputSchema(array $endpoint): array {
		$properties = [];
		$required = [];

		foreach ($endpoint['pathParams'] ?? [] as $parameter) {
			/** @var ApiPathParam $parameter */
			$properties[$parameter->name] = $this->buildParameterSchema($parameter);
			$required[] = $parameter->name;
		}

		foreach ($endpoint['queryParams'] ?? [] as $parameter) {
			/** @var ApiQueryParam $parameter */
			$properties[$parameter->name] = $this->buildParameterSchema($parameter);
			if ($parameter->required) {
				$required[] = $parameter->name;
			}
		}

		foreach ($endpoint['bodyParams'] ?? [] as $parameter) {
			/** @var ApiBodyParam $parameter */
			$properties[$parameter->name] = $this->buildParameterSchema($parameter);
			if ($parameter->required) {
				$required[] = $parameter->name;
			}
		}

		$schema = [
			'type' => 'object',
			'properties' => $properties !== [] ? $properties : new stdClass(),
			'additionalProperties' => FALSE,
		];
		if ($required !== []) {
			$schema['required'] = array_values(array_unique($required));
		}

		return $schema;
	}

	/**
	 * @return array<string, mixed>
	 */
	protected function buildParameterSchema(ApiPathParam|ApiQueryParam|ApiBodyParam $parameter): array {
		$type = strtolower((string) ($parameter->type ?? 'string'));
		$schema = [
			'type' => match ($type) {
				'int', 'integer' => 'integer',
				'float', 'double', 'number' => 'number',
				'bool', 'boolean' => 'boolean',
				'array' => 'array',
				'object' => 'object',
				default => 'string',
			},
		];

		if ($schema['type'] === 'array') {
			$schema['items'] = [
				'anyOf' => [
					['type' => 'string'],
					['type' => 'integer'],
				],
			];
		}

		if (($parameter->description ?? '') !== '') {
			$schema['description'] = (string) $parameter->description;
		}
		if (($parameter->example ?? NULL) !== NULL) {
			$schema['example'] = $parameter->example;
		}
		if (($parameter->pattern ?? NULL) !== NULL) {
			$schema['pattern'] = (string) $parameter->pattern;
		}
		if (($parameter->min ?? NULL) !== NULL) {
			$schema['minimum'] = $parameter->min;
		}
		if (($parameter->max ?? NULL) !== NULL) {
			$schema['maximum'] = $parameter->max;
		}
		if (($parameter->minLength ?? NULL) !== NULL) {
			$schema['minLength'] = $parameter->minLength;
		}
		if (($parameter->maxLength ?? NULL) !== NULL) {
			$schema['maxLength'] = $parameter->maxLength;
		}

		return $schema;
	}

	/**
	 */
	protected function buildToolName(string $apiId, string $httpMethod, string $path): string {
		$pathSlug = trim($path, '/');
		$pathSlug = preg_replace('/\{([a-zA-Z0-9_]+)\}/', 'by_$1', $pathSlug) ?? $pathSlug;
		$pathSlug = preg_replace('/[^a-zA-Z0-9_]+/', '_', $pathSlug) ?? $pathSlug;
		$pathSlug = trim((string) $pathSlug, '_');
		if ($pathSlug === '') {
			$pathSlug = 'root';
		}

		$name = strtolower($apiId . '_' . strtolower($httpMethod) . '_' . $pathSlug);
		$name = preg_replace('/_+/', '_', $name) ?? $name;
		return trim($name, '_');
	}

	/**
	 */
	protected function buildEndpointId(string $apiId, string $version, string $httpMethod, string $path): string {
		return strtolower($apiId . ':' . $version . ':' . $httpMethod . ':' . $path);
	}

	/**
	 */
	protected function buildResolvedToolsCacheKey(
		string $apiId,
		string $version,
		string $authMode,
		string $tenantId,
		?AuthContext $authContext
	): string {
		$scopes = $authContext?->getScopes() ?? [];
		sort($scopes);

		return sha1(json_encode([
			'apiId' => $apiId,
			'version' => $version,
			'authMode' => $authMode,
			'tenantId' => $tenantId,
			'scopes' => $scopes,
		]) ?: '');
	}

	/**
	 * @param Endpoint $endpoint
	 */
	protected function isEndpointAllowedForAuthContext(array $endpoint, ?AuthContext $authContext): bool {
		$requiredScopes = array_values(array_filter(
			$endpoint['scopes'],
			static fn (string $scope): bool => $scope !== ''
		));
		if ($requiredScopes === []) {
			return TRUE;
		}

		if ($authContext === NULL) {
			return FALSE;
		}

		foreach ($requiredScopes as $scope) {
			if (!$authContext->hasScope($scope)) {
				return FALSE;
			}
		}

		return TRUE;
	}

	/**
	 * @return McpConfig
	 */
	protected function normalizeMcpConfig(mixed $mcpConfig): array {
		if (!$mcpConfig instanceof ApiMcp) {
			return [
				'exclude' => FALSE,
				'name' => NULL,
				'description' => NULL,
				'notes' => NULL,
			];
		}

		return [
			'exclude' => $mcpConfig->exclude,
			'name' => $mcpConfig->name,
			'description' => $mcpConfig->description,
			'notes' => $mcpConfig->notes,
		];
	}

	/**
	 * @param list<string> $denylist
	 * @param list<string> $candidates
	 */
	protected function matchesDenylist(array $denylist, array $candidates): bool {
		if ($denylist === [] || $candidates === []) {
			return FALSE;
		}

		$normalizedCandidates = array_map(
			static fn (string $candidate): string => strtolower(trim($candidate)),
			array_filter($candidates, static fn (string $candidate): bool => $candidate !== '')
		);

		foreach ($denylist as $entry) {
			if ($entry === '') {
				continue;
			}
			if (str_contains($entry, '*')) {
				foreach ($normalizedCandidates as $candidate) {
					if (fnmatch($entry, $candidate)) {
						return TRUE;
					}
				}
				continue;
			}

			if (\in_array($entry, $normalizedCandidates, TRUE)) {
				return TRUE;
			}
		}

		return FALSE;
	}

	/**
	 * @param list<string> $entries
	 * @return list<string>
	 */
	protected function normalizeDenylist(array $entries): array {
		$normalized = [];
		foreach ($entries as $entry) {
			$value = strtolower(trim($entry));
			if ($value !== '') {
				$normalized[] = $value;
			}
		}
		return array_values(array_unique($normalized));
	}

	/**
	 */
	protected function mapStatusToErrorCategory(int $status): string {
		if ($status === 400 || $status === 422) {
			return 'validation_error';
		}
		if ($status === 401 || $status === 403) {
			return 'auth_error';
		}
		if ($status === 402 || $status === 429) {
			return 'rate_limit_or_quota_error';
		}
		return 'upstream_error';
	}

	/**
	 * @param JsonPayload $payload
	 * @return ToolResult
	 */
	protected function createToolErrorResult(
		string $category,
		int $statusCode,
		string $message,
		array $payload = []
	): array {
		return [
			'isError' => TRUE,
			'content' => [
				[
					'type' => 'text',
					'text' => $message,
				],
			],
			'structuredContent' => [
				'errorCategory' => $category,
				'status' => $statusCode,
				'upstream' => $payload,
			],
		];
	}

	/**
	 */
	protected function isConventionExcludedPath(string $path): bool {
		$normalizedPath = strtolower(trim($path));
		if ($normalizedPath === '') {
			return FALSE;
		}

		$normalizedPath = '/' . ltrim($normalizedPath, '/');
		if (str_starts_with($normalizedPath, '/docs')) {
			return TRUE;
		}
		if (str_starts_with($normalizedPath, '/demo')) {
			return TRUE;
		}
		if (str_starts_with($normalizedPath, '/internal')) {
			return TRUE;
		}

		return FALSE;
	}
}
