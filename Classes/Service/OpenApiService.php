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
use SGalinski\SgApiCore\Attribute\ApiPathParam;
use SGalinski\SgApiCore\Attribute\ApiQueryParam;
use SGalinski\SgApiCore\Attribute\ApiResponse;
use SGalinski\SgApiCore\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
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
	 * @var FrontendInterface
	 */
	protected FrontendInterface $cache;

	/**
	 * @param EndpointDiscoveryService $endpointDiscoveryService
	 * @param ApiRegistry $apiRegistry
	 * @param ExtensionConfiguration $extensionConfiguration
	 * @param CacheManager $cacheManager
	 */
	public function __construct(
		EndpointDiscoveryService $endpointDiscoveryService,
		ApiRegistry $apiRegistry,
		ExtensionConfiguration $extensionConfiguration,
		CacheManager $cacheManager
	) {
		$this->endpointDiscoveryService = $endpointDiscoveryService;
		$this->apiRegistry = $apiRegistry;
		$this->extensionConfiguration = $extensionConfiguration;
		$this->cache = $cacheManager->getCache('sg_apicore_discovery');
	}

	/**
	 * Generates the OpenAPI specification for a given API ID and version
	 *
	 * @param string $apiId
	 * @param string $version
	 * @param string $baseUrl
	 * @return array
	 * @throws \ReflectionException
	 */
	public function generateSpec(string $apiId, string $version, string $baseUrl = ''): array {
		$securityConfig = $this->apiRegistry->getSecurityConfig($apiId, $version);
		$authMode = $securityConfig['authMode'] ?? 'token';
		if ($baseUrl === '') {
			$apiPathPrefix = $this->extensionConfiguration->getApiPathPrefix();
			$baseUrl = rtrim($apiPathPrefix, '/') . '/' . $apiId . '/v' . $version;
		}

		$cacheKey = $this->getSpecCacheKey($apiId, $version, $authMode, $baseUrl);
		$cachedSpec = $this->cache->get($cacheKey);
		if (is_array($cachedSpec)) {
			return $cachedSpec;
		}

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
				],
				'schemas' => []
			]
		];

		if ($authMode !== 'public') {
			$spec['security'] = [['bearerAuth' => []]];
		}

		$endpoints = $this->endpointDiscoveryService->getAllEndpoints();
		$filteredEndpoints = [];
		$allTags = [];
		foreach ($endpoints as $endpoint) {
			// Filter by API ID, version and auth mode if specified
			if (!empty($endpoint['apiId']) && !in_array($apiId, $endpoint['apiId'], TRUE)) {
				continue;
			}
			if (!empty($endpoint['version']) && !in_array($version, $endpoint['version'], TRUE)) {
				continue;
			}
			if (!empty($endpoint['authMode'])) {
				// Visibility logic
				$restrictedTo = array_filter($endpoint['authMode'], static fn ($m) => $m !== 'public');
				if (!empty($restrictedTo)) {
					if (!in_array($authMode, $restrictedTo, TRUE)) {
						continue;
					}
				} elseif (!in_array('public', $endpoint['authMode'], TRUE)) {
					continue;
				}
			}

			$filteredEndpoints[] = $endpoint;
			foreach ($endpoint['tags'] as $tag) {
				$allTags[$tag] = TRUE;
			}

			// Collect schemas for resources
			if (isset($endpoint['resource'])) {
				$tableName = $endpoint['resource']['table'];
				if (!isset($spec['components']['schemas'][$tableName])) {
					$spec['components']['schemas'][$tableName] = $this->generateResourceSchema($endpoint);
				}
			}
		}

		// Sort tags alphabetically but keep specific tags at the end
		$tagNames = array_keys($allTags);
		natcasesort($tagNames);
		$bottomTags = ['openapi', 'health'];
		$sortedTags = [];
		$tagsToPutAtBottom = [];

		foreach ($tagNames as $tagName) {
			if (in_array(strtolower($tagName), $bottomTags, TRUE)) {
				$tagsToPutAtBottom[] = ['name' => $tagName];
			} else {
				$sortedTags[] = ['name' => $tagName];
			}
		}
		$spec['tags'] = array_merge($sortedTags, $tagsToPutAtBottom);

		foreach ($filteredEndpoints as $endpoint) {
			$this->addRouteToSpec($spec, $endpoint);
		}

		$this->cache->set($cacheKey, $spec);
		return $spec;
	}

	/**
	 * @param string $apiId
	 * @param string $version
	 * @param string $authMode
	 * @param string $baseUrl
	 * @return string
	 * @throws \ReflectionException
	 */
	protected function getSpecCacheKey(string $apiId, string $version, string $authMode, string $baseUrl): string {
		$signature = $this->endpointDiscoveryService->getDiscoverySignature();
		$apiPathPrefix = $this->extensionConfiguration->getApiPathPrefix();
		$cachePayload = implode('|', [$apiId, $version, $authMode, $baseUrl, $apiPathPrefix, $signature]);
		return 'openapi_' . md5($cachePayload);
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
				$type = $this->mapPhpTypeToOpenApi($param->type);
				$propertySpec = [
					'type' => $type,
					'description' => (string) $param->description
				];
				if ($type === 'array') {
					$propertySpec['items'] = [
						'anyOf' => [
							['type' => 'string'],
							['type' => 'integer']
						]
					];
				}
				if ($param->example !== NULL) {
					$propertySpec['example'] = $param->example;
				}
				if ($param->pattern !== NULL) {
					$propertySpec['pattern'] = $this->stripRegexDelimiters($param->pattern);
				}
				if ($param->min !== NULL) {
					$propertySpec['minimum'] = $param->min;
				}
				if ($param->max !== NULL) {
					$propertySpec['maximum'] = $param->max;
				}
				if ($param->minLength !== NULL) {
					$propertySpec['minLength'] = $param->minLength;
				}
				if ($param->maxLength !== NULL) {
					$propertySpec['maxLength'] = $param->maxLength;
				}
				if ($param->requiredIf !== NULL) {
					$propertySpec['description'] .= "\n\n**Required if:** " . $param->requiredIf;
				}

				$properties[$param->name] = $propertySpec;
				if ($param->required) {
					$required[] = $param->name;
				}
			}

			$schema = [
				'type' => 'object',
				'properties' => $properties
			];

			if (!empty($required)) {
				$schema['required'] = $required;
			}

			$operation['requestBody'] = [
				'required' => TRUE,
				'content' => [
					'application/json' => [
						'schema' => $schema
					]
				]
			];
		}

		// Parameters (Query, Path)
		$operation['parameters'] = [];
		/** @var ApiQueryParam $param */
		foreach ($endpoint['queryParams'] as $param) {
			$type = $this->mapPhpTypeToOpenApi($param->type);
			if ($type === 'string' && is_array($param->example)) {
				$type = 'array';
			}

			$schema = [
				'type' => $type
			];
			if ($type === 'array') {
				$schema['items'] = [
					'anyOf' => [
						['type' => 'string'],
						['type' => 'integer']
					]
				];
			}
			if ($param->example !== NULL) {
				$schema['example'] = $param->example;
			}
			if ($param->pattern !== NULL) {
				$schema['pattern'] = $this->stripRegexDelimiters($param->pattern);
			}
			if ($param->min !== NULL) {
				$schema['minimum'] = $param->min;
			}
			if ($param->max !== NULL) {
				$schema['maximum'] = $param->max;
			}
			if ($param->minLength !== NULL) {
				$schema['minLength'] = $param->minLength;
			}
			if ($param->maxLength !== NULL) {
				$schema['maxLength'] = $param->maxLength;
			}

			$description = (string) $param->description;
			if ($param->requiredIf !== NULL) {
				$description .= "\n\n**Required if:** " . $param->requiredIf;
			}

			$parameterSpec = [
				'name' => $param->name,
				'in' => 'query',
				'required' => $param->required,
				'description' => $description,
				'schema' => $schema
			];
			if ($type === 'array' || ($param->type === 'string' && (str_ends_with($param->name, '[]') || is_array($param->example)))) {
				$parameterSpec['explode'] = TRUE;
				$parameterSpec['style'] = 'form';
			}
			$operation['parameters'][] = $parameterSpec;
		}
		/** @var ApiPathParam $param */
		foreach ($endpoint['pathParams'] as $param) {
			$type = $this->mapPhpTypeToOpenApi($param->type);
			$schema = [
				'type' => $type
			];
			if ($type === 'array') {
				$schema['items'] = [
					'anyOf' => [
						['type' => 'string'],
						['type' => 'integer']
					]
				];
			}
			if ($param->example !== NULL) {
				$schema['example'] = $param->example;
			}
			if ($param->pattern !== NULL) {
				$schema['pattern'] = $this->stripRegexDelimiters($param->pattern);
			}
			if ($param->min !== NULL) {
				$schema['minimum'] = $param->min;
			}
			if ($param->max !== NULL) {
				$schema['maximum'] = $param->max;
			}
			if ($param->minLength !== NULL) {
				$schema['minLength'] = $param->minLength;
			}
			if ($param->maxLength !== NULL) {
				$schema['maxLength'] = $param->maxLength;
			}

			$parameterSpec = [
				'name' => $param->name,
				'in' => 'path',
				'required' => TRUE,
				'description' => (string) $param->description,
				'schema' => $schema
			];
			$operation['parameters'][] = $parameterSpec;
		}

		// Responses
		/** @var ApiResponse $response */
		foreach ($endpoint['responses'] as $response) {
			$resp = [
				'description' => (string) $response->description
			];
			if ($response->schema || $response->example !== NULL) {
				$resp['content'] = [
					'application/json' => [
						'schema' => $this->parseSchema(
							$response->schema,
							array_keys($spec['components']['schemas'] ?? [])
						)
					]
				];
				if ($response->example !== NULL) {
					$resp['content']['application/json']['example'] = $response->example;
				}
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
	 * Generates an OpenAPI schema for a resource based on the endpoint metadata
	 *
	 * @param array $endpoint
	 * @return array
	 */
	protected function generateResourceSchema(array $endpoint): array {
		$properties = [];
		$required = [];

		if (isset($endpoint['resource']['fieldMetadata'])) {
			foreach ($endpoint['resource']['fieldMetadata'] as $fieldName => $meta) {
				$type = $this->mapPhpTypeToOpenApi($meta['type']);
				$property = [
					'type' => $type,
					'description' => (string) $meta['description']
				];
				if ($type === 'array') {
					$property['items'] = [
						'anyOf' => [
							['type' => 'string'],
							['type' => 'integer']
						]
					];
				}
				$properties[$fieldName] = $property;
			}
		}

		// Also check bodyParams for required flags or additional info
		/** @var ApiBodyParam $param */
		foreach ($endpoint['bodyParams'] as $param) {
			if (!isset($properties[$param->name])) {
				$type = $this->mapPhpTypeToOpenApi($param->type);
				$property = [
					'type' => $type,
					'description' => (string) $param->description
				];
				if ($type === 'array') {
					$property['items'] = [
						'anyOf' => [
							['type' => 'string'],
							['type' => 'integer']
						]
					];
				}
				$properties[$param->name] = $property;
			}
			if ($param->required) {
				$required[] = $param->name;
			}
		}

		$schema = [
			'type' => 'object',
			'properties' => $properties
		];

		if (!empty($required)) {
			$schema['required'] = array_unique($required);
		}

		return $schema;
	}

	/**
	 * Strips regex delimiters from a pattern for OpenAPI compatibility
	 *
	 * @param string $pattern
	 * @return string
	 */
	protected function stripRegexDelimiters(string $pattern): string {
		if ($pattern === '') {
			return '';
		}

		if (str_starts_with($pattern, '/') && str_ends_with($pattern, '/')) {
			return substr($pattern, 1, -1);
		}

		return $pattern;
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
	 * @param string|null $schemaStr
	 * @param array $knownSchemas
	 * @return array
	 */
	protected function parseSchema(?string $schemaStr, array $knownSchemas = []): array {
		if ($schemaStr === NULL) {
			return ['type' => 'object', 'description' => ''];
		}
		if (str_ends_with($schemaStr, '[]')) {
			$baseSchema = substr($schemaStr, 0, -2);
			if (in_array($baseSchema, $knownSchemas, TRUE)) {
				return [
					'type' => 'array',
					'items' => ['$ref' => '#/components/schemas/' . $baseSchema]
				];
			}

			return [
				'type' => 'array',
				'items' => ['type' => 'object', 'description' => $baseSchema]
			];
		}

		if (in_array($schemaStr, $knownSchemas, TRUE)) {
			return ['$ref' => '#/components/schemas/' . $schemaStr];
		}

		return ['type' => 'object', 'description' => $schemaStr];
	}
}
