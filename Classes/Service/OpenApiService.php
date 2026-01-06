<?php

/***************************************************************
 *  Copyright notice
 *
 *  (c) sgalinski Internet Services (https://www.sgalinski.de)
 *
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/

namespace SGalinski\SgApiCore\Service;

use SGalinski\SgApiCore\Attribute\ApiBodyParam;
use SGalinski\SgApiCore\Attribute\ApiEndpoint;
use SGalinski\SgApiCore\Attribute\ApiPathParam;
use SGalinski\SgApiCore\Attribute\ApiQueryParam;
use SGalinski\SgApiCore\Attribute\ApiResponse;
use SGalinski\SgApiCore\Attribute\ApiRoute;
use SGalinski\SgApiCore\Attribute\RequireScopes;
use SGalinski\SgApiCore\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\SingletonInterface;

/**
 * Service to generate OpenAPI specifications
 */
class OpenApiService implements SingletonInterface {
	/**
	 * @var EndpointDiscoveryService
	 */
	protected EndpointDiscoveryService $endpointDiscoveryService;

	/**
	 * @var ApiRegistry
	 */
	protected ApiRegistry $apiRegistry;

	/**
	 * @var ExtensionConfiguration
	 */
	protected ExtensionConfiguration $extensionConfiguration;

	/**
	 * @param EndpointDiscoveryService $endpointDiscoveryService
	 * @param ApiRegistry $apiRegistry
	 * @param ExtensionConfiguration $extensionConfiguration
	 */
	public function __construct(
		EndpointDiscoveryService $endpointDiscoveryService,
		ApiRegistry $apiRegistry,
		ExtensionConfiguration $extensionConfiguration
	) {
		$this->endpointDiscoveryService = $endpointDiscoveryService;
		$this->apiRegistry = $apiRegistry;
		$this->extensionConfiguration = $extensionConfiguration;
	}

	/**
	 * Generates the OpenAPI specification for a given API ID and version
	 *
	 * @param string $apiId
	 * @param string $version
	 * @return array
	 * @throws \ReflectionException
	 */
	public function generateSpec(string $apiId, string $version): array {
		$securityConfig = $this->apiRegistry->getSecurityConfig($apiId, $version);
		$authMode = $securityConfig['authMode'] ?? 'token';
		$apiPathPrefix = $this->extensionConfiguration->getApiPathPrefix();
		$baseUrl = rtrim($apiPathPrefix, '/') . '/' . $apiId . '/v' . $version;

		$spec = [
			'openapi' => '3.0.3',
			'info' => [
				'title' => 'API: ' . $apiId . ' (v' . $version . ')',
				'version' => $version,
				'description' => 'Automatically generated OpenAPI specification for the ' . $apiId . ' API.'
			],
			'servers' => [
				[
					'url' => $baseUrl,
					'description' => 'Current API server'
				]
			],
			'paths' => [],
			'components' => [
				'securitySchemes' => [
					'bearerAuth' => [
						'type' => 'http',
						'scheme' => 'bearer'
					]
				]
			]
		];

		if ($authMode !== 'public') {
			$spec['security'] = [['bearerAuth' => []]];
		}

		foreach ($this->endpointDiscoveryService->getAllEndpoints() as $endpoint) {
			// Filter by API ID, version and auth mode if specified
			if ($endpoint['apiId'] !== NULL) {
				$apiIds = is_array($endpoint['apiId']) ? $endpoint['apiId'] : [$endpoint['apiId']];
				if (!in_array($apiId, $apiIds, TRUE)) {
					continue;
				}
			}
			if ($endpoint['version'] !== NULL) {
				$versions = is_array($endpoint['version']) ? $endpoint['version'] : [$endpoint['version']];
				if (!in_array($version, $versions, TRUE)) {
					continue;
				}
			}
			if ($endpoint['authMode'] !== NULL) {
				$authModes = is_array($endpoint['authMode']) ? $endpoint['authMode'] : [$endpoint['authMode']];

				// Visibility logic
				$restrictedTo = array_filter($authModes, static fn ($m) => $m !== 'public');
				if (!empty($restrictedTo)) {
					if (!in_array($authMode, $restrictedTo, TRUE)) {
						continue;
					}
				} elseif (!in_array('public', $authModes, TRUE)) {
					continue;
				}
			}

			$this->addRouteToSpec($spec, $endpoint);
		}

		return $spec;
	}

	/**
	 * Adds a route and its metadata to the OpenAPI spec
	 *
	 * @param array &$spec
	 * @param array $endpoint
	 */
	protected function addRouteToSpec(array &$spec, array $endpoint): void {
		$path = '/' . ltrim($endpoint['path'], '/');

		if (!isset($spec['paths'][$path])) {
			$spec['paths'][$path] = [];
		}

		$operation = [
			'summary' => $endpoint['summary'],
			'description' => $endpoint['description'],
			'tags' => $endpoint['tags'],
			'responses' => []
		];

		if (count($endpoint['scopes']) > 0) {
			$operation['description'] .= "\n\n**Required Scopes:** " . implode(', ', $endpoint['scopes']);
		}

		// Request Body (JSON)
		if (count($endpoint['bodyParams']) > 0) {
			$properties = [];
			$required = [];
			/** @var ApiBodyParam $param */
			foreach ($endpoint['bodyParams'] as $param) {
				$properties[$param->name] = [
					'type' => $this->mapPhpTypeToOpenApi($param->type),
					'description' => $param->description
				];
				if ($param->required) {
					$required[] = $param->name;
				}
			}

			$operation['requestBody'] = [
				'required' => TRUE,
				'content' => [
					'application/json' => [
						'schema' => [
							'type' => 'object',
							'properties' => $properties
						]
					]
				]
			];

			if (!empty($required)) {
				$operation['requestBody']['content']['application/json']['schema']['required'] = $required;
			}
		}

		// Parameters (Query, Path)
		$operation['parameters'] = [];
		/** @var ApiQueryParam $param */
		foreach ($endpoint['queryParams'] as $param) {
			$operation['parameters'][] = [
				'name' => $param->name,
				'in' => 'query',
				'required' => $param->required,
				'description' => $param->description,
				'schema' => ['type' => $this->mapPhpTypeToOpenApi($param->type)]
			];
		}
		/** @var ApiPathParam $param */
		foreach ($endpoint['pathParams'] as $param) {
			$operation['parameters'][] = [
				'name' => $param->name,
				'in' => 'path',
				'required' => TRUE,
				'description' => $param->description,
				'schema' => ['type' => $this->mapPhpTypeToOpenApi($param->type)]
			];
		}

		// Responses
		/** @var ApiResponse $response */
		foreach ($endpoint['responses'] as $response) {
			$resp = [
				'description' => $response->description
			];
			if ($response->schema) {
				$resp['content'] = [
					'application/json' => [
						'schema' => $this->parseSchema($response->schema)
					]
				];
			}
			$operation['responses'][(string) $response->status] = $resp;
		}

		// Default response if none defined
		if (empty($operation['responses'])) {
			$operation['responses']['200'] = ['description' => 'Success'];
		}

		foreach ($endpoint['methods'] as $httpMethod) {
			$spec['paths'][$path][strtolower($httpMethod)] = $operation;
		}
	}

	/**
	 * Maps common PHP types to OpenAPI types
	 *
	 * @param string $phpType
	 * @return string
	 */
	protected function mapPhpTypeToOpenApi(string $phpType): string {
		return match (strtolower($phpType)) {
			'int', 'integer' => 'integer',
			'float', 'double' => 'number',
			'bool', 'boolean' => 'boolean',
			'array' => 'array',
			default => 'string',
		};
	}

	/**
	 * Very basic schema parser (handles primitives and "Item[]" for arrays)
	 *
	 * @param string $schemaStr
	 * @return array
	 */
	protected function parseSchema(string $schemaStr): array {
		if (str_ends_with($schemaStr, '[]')) {
			return [
				'type' => 'array',
				'items' => ['type' => 'object', 'description' => substr($schemaStr, 0, -2)]
			];
		}
		return ['type' => 'object', 'description' => $schemaStr];
	}
}
