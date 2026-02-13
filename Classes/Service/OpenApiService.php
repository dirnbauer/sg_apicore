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
	 * @var SchemaRegistry
	 */
	protected SchemaRegistry $schemaRegistry;

	/**
	 * @var FrontendInterface
	 */
	protected FrontendInterface $cache;

	/**
	 * @param EndpointDiscoveryService $endpointDiscoveryService
	 * @param ApiRegistry $apiRegistry
	 * @param SchemaRegistry $schemaRegistry
	 * @param ExtensionConfiguration $extensionConfiguration
	 * @param CacheManager $cacheManager
	 */
	public function __construct(
		EndpointDiscoveryService $endpointDiscoveryService,
		ApiRegistry $apiRegistry,
		SchemaRegistry $schemaRegistry,
		ExtensionConfiguration $extensionConfiguration,
		CacheManager $cacheManager
	) {
		$this->endpointDiscoveryService = $endpointDiscoveryService;
		$this->apiRegistry = $apiRegistry;
		$this->schemaRegistry = $schemaRegistry;
		$this->extensionConfiguration = $extensionConfiguration;
		$this->cache = $cacheManager->getCache('sg_apicore_discovery');
	}

	/**
	 * Generates the OpenAPI specification for a given API ID and version
	 *
	 * @param string $apiId
	 * @param string $version
	 * @param string $baseUrl
	 * @param string $tenantId
	 * @return array
	 * @throws \ReflectionException
	 */
	public function generateSpec(string $apiId, string $version, string $baseUrl = '', string $tenantId = ''): array {
		$securityConfig = $this->apiRegistry->getSecurityConfig($apiId, $version);
		$authMode = $securityConfig['authMode'] ?? 'token';
		if ($baseUrl === '') {
			$apiPathPrefix = $this->extensionConfiguration->getApiPathPrefix();
			$baseUrl = rtrim($apiPathPrefix, '/') . '/' . $apiId . '/v' . $version;
		}

		$cacheKey = $this->getSpecCacheKey($apiId, $version, $authMode, $baseUrl, $tenantId);
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
				'schemas' => $this->enrichGlobalSchemas()
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
			if ($tenantId !== '' && !empty($endpoint['tenants']) && !in_array($tenantId, $endpoint['tenants'], TRUE)) {
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

		// Sort endpoints by tag and path for better readability in the documentation.
		// The original order from EndpointDiscoveryService is optimized for routing (specific before generic).
		usort($filteredEndpoints, static function ($a, $b) {
			$tagA = $a['tags'][0] ?? '';
			$tagB = $b['tags'][0] ?? '';
			$tagCompare = strcasecmp($tagA, $tagB);
			if ($tagCompare !== 0) {
				return $tagCompare;
			}

			$pathCompare = strcasecmp($a['path'], $b['path']);
			if ($pathCompare !== 0) {
				return $pathCompare;
			}

			return strcasecmp(implode(',', $a['methods']), implode(',', $b['methods']));
		});

		foreach ($filteredEndpoints as $endpoint) {
			$this->addRouteToSpec($spec, $endpoint);
		}

		$this->cache->set($cacheKey, $spec);
		return $spec;
	}

	/**
	 * Returns debug information for OpenAPI caching
	 *
	 * @param string $apiId
	 * @param string $version
	 * @param string $baseUrl
	 * @param string $tenantId
	 * @return array<string, string>
	 * @throws \ReflectionException
	 */
	public function getCacheDebugInfo(string $apiId, string $version, string $baseUrl = '', string $tenantId = ''): array {
		$securityConfig = $this->apiRegistry->getSecurityConfig($apiId, $version);
		$authMode = $securityConfig['authMode'] ?? 'token';
		if ($baseUrl === '') {
			$apiPathPrefix = $this->extensionConfiguration->getApiPathPrefix();
			$baseUrl = rtrim($apiPathPrefix, '/') . '/' . $apiId . '/v' . $version;
		}

		return [
			'cacheKey' => $this->getSpecCacheKey($apiId, $version, $authMode, $baseUrl, $tenantId),
			'signature' => $this->endpointDiscoveryService->getDiscoverySignature(),
		];
	}

	/**
	 * @param string $apiId
	 * @param string $version
	 * @param string $authMode
	 * @param string $baseUrl
	 * @param string $tenantId
	 * @return string
	 * @throws \ReflectionException
	 */
	protected function getSpecCacheKey(string $apiId, string $version, string $authMode, string $baseUrl, string $tenantId = ''): string {
		$signature = $this->endpointDiscoveryService->getDiscoverySignature();
		$apiPathPrefix = $this->extensionConfiguration->getApiPathPrefix();
		$cachePayload = implode('|', [$apiId, $version, $authMode, $baseUrl, $apiPathPrefix, $signature, $tenantId]);
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
			if ($type === 'array' || str_ends_with($param->name, '[]') || is_array($param->example)) {
				$parameterSpec['explode'] = str_ends_with($param->name, '[]');
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
				$schema = $this->parseSchema(
					$response->schema,
					array_merge(
						array_keys($spec['components']['schemas'] ?? []),
						array_keys($this->schemaRegistry->getSchemas())
					)
				);

				$example = $response->example;
				if ($example !== NULL) {
					if ($response->schema === NULL || ($schema['type'] ?? NULL) === 'object' || isset($schema['$ref'])) {
						$tableName = '';
						$refSchema = NULL;
						if (isset($schema['$ref'])) {
							$refName = basename($schema['$ref']);
							$refSchema = $this->schemaRegistry->getSchema($refName);
							$tableName = $this->schemaRegistry->getTableNameForSchema($refName);
						}

						$generatedSchema = $this->generateSchemaFromExample(
							$example,
							$tableName !== '' ? $tableName : (string) $response->schema
						);

						if ($response->schema === NULL) {
							$schema = $generatedSchema;
						} else {
							$baseSchema = $refSchema ?? $schema;
							if (($baseSchema['type'] ?? '') === 'array' && isset($baseSchema['items']['$ref'])) {
								$refName = basename($baseSchema['items']['$ref']);
								$baseSchema['items'] = $this->schemaRegistry->getSchema(
									$refName
								) ?? $baseSchema['items'];
							}

							// Merge properties, prioritizing the defined schema's structure
							// but allowing the example to add/override if necessary.
							$schema = $baseSchema;
							if (($schema['type'] ?? '') === 'array' && isset($schema['items']['properties'])) {
								$schema['items']['properties'] = array_merge(
									$schema['items']['properties'],
									$generatedSchema['items']['properties'] ?? []
								);
							} elseif (isset($schema['properties'])) {
								$schema['properties'] = array_merge(
									$schema['properties'],
									$generatedSchema['properties'] ?? []
								);
							} elseif (($schema['type'] ?? '') === 'object' && isset($generatedSchema['properties'])) {
								// Case where base schema was just {type: object} without properties
								$schema['properties'] = $generatedSchema['properties'];
							}
						}
					}

					// Resolve placeholders in example for documentation display
					$example = $this->resolveExamplePlaceholders($example);
				}

				$resp['content'] = [
					'application/json' => [
						'schema' => $schema
					]
				];

				if ($example !== NULL) {
					$resp['content']['application/json']['example'] = $example;
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
	 * Resolves 'schema:...' placeholders in example data with dummy/stub values
	 *
	 * @param mixed $example
	 * @return mixed
	 */
	protected function resolveExamplePlaceholders(mixed $example): mixed {
		if (is_string($example) && str_starts_with($example, 'schema:')) {
			$schemaStr = substr($example, 7);
			$isArray = str_ends_with($schemaStr, '[]');
			$schemaName = $isArray ? substr($schemaStr, 0, -2) : $schemaStr;

			$schema = $this->schemaRegistry->getSchema($schemaName);
			if ($schema === NULL) {
				// Try to generate a stub from table name if it's not a registered schema
				return $isArray ? [[]] : [];
			}

			$stub = $this->generateStubFromSchema($schema);
			return $isArray ? [$stub] : $stub;
		}

		if (is_array($example)) {
			foreach ($example as $key => $value) {
				$example[$key] = $this->resolveExamplePlaceholders($value);
			}
		}

		return $example;
	}

	/**
	 * Generates a stub object with example values from a schema
	 *
	 * @param array $schema
	 * @return mixed
	 */
	protected function generateStubFromSchema(array $schema): mixed {
		if (isset($schema['$ref'])) {
			$refName = basename($schema['$ref']);
			$refSchema = $this->schemaRegistry->getSchema($refName);
			return $refSchema ? $this->generateStubFromSchema($refSchema) : [];
		}

		$type = $schema['type'] ?? 'object';
		if ($type === 'array') {
			return [$this->generateStubFromSchema($schema['items'] ?? [])];
		}

		if ($type === 'object' && isset($schema['properties'])) {
			$stub = [];
			foreach ($schema['properties'] as $name => $prop) {
				if (isset($prop['$ref'])) {
					$refName = basename($prop['$ref']);
					$refSchema = $this->schemaRegistry->getSchema($refName);
					$stub[$name] = $refSchema ? $this->generateStubFromSchema($refSchema) : [];
				} else {
					$stub[$name] = $this->generateStubFromProp($prop);
				}
			}
			return $stub;
		}

		return $this->generateStubFromProp($schema);
	}

	/**
	 * Generates a stub value for a property
	 *
	 * @param array $prop
	 * @return mixed
	 */
	protected function generateStubFromProp(array $prop): mixed {
		if (isset($prop['example'])) {
			return $prop['example'];
		}

		$type = $prop['type'] ?? 'string';
		return match ($type) {
			'integer' => 1,
			'number' => 1.0,
			'boolean' => TRUE,
			'array' => isset($prop['items']) ? [$this->generateStubFromSchema($prop['items'])] : [],
			'object' => isset($prop['properties']) ? $this->generateStubFromSchema($prop) : [],
			default => 'string',
		};
	}

	/**
	 * Enriches all global schemas with TCA metadata
	 *
	 * @return array
	 */
	protected function enrichGlobalSchemas(): array {
		$schemas = $this->schemaRegistry->getSchemas();
		foreach ($schemas as $schemaName => &$schema) {
			$tableName = $this->schemaRegistry->getTableNameForSchema($schemaName);
			if ($tableName !== '') {
				$schema = $this->enrichSchemaWithTca($schema, $tableName);
			}
		}
		return $schemas;
	}

	/**
	 * Recursively enriches a schema with labels and descriptions from TCA
	 *
	 * @param array $schema
	 * @param string $tableName
	 * @return array
	 */
	protected function enrichSchemaWithTca(array $schema, string $tableName): array {
		if (isset($schema['$ref'])) {
			$refName = basename($schema['$ref']);
			$refSchema = $this->schemaRegistry->getSchema($refName);
			if ($refSchema) {
				$table = $this->schemaRegistry->getTableNameForSchema($refName);
				$schema = array_merge(
					$schema,
					$this->enrichSchemaWithTca($refSchema, $table !== '' ? $table : $tableName)
				);
				unset($schema['$ref']); // Inline resolved schema for enrichment
			}
			return $schema;
		}

		if (($schema['type'] ?? '') === 'array' && isset($schema['items'])) {
			$schema['items'] = $this->enrichSchemaWithTca($schema['items'], $tableName);
			return $schema;
		}

		if (($schema['type'] ?? '') !== 'object' || !isset($schema['properties'])) {
			return $schema;
		}

		$tca = (isset($GLOBALS['TCA'][$tableName])) ? $GLOBALS['TCA'][$tableName] : NULL;
		if ($tca === NULL) {
			return $schema;
		}

		$languageService = $this->endpointDiscoveryService->getLanguageService();
		foreach ($schema['properties'] as $fieldName => &$property) {
			$tcaFieldName = $property['x-tca-field'] ?? $fieldName;
			$columnConfig = $tca['columns'][$tcaFieldName] ?? NULL;
			if ($columnConfig !== NULL) {
				if (isset($columnConfig['label'])) {
					$label = (string) $languageService->sL($columnConfig['label']);
					if ($label !== '') {
						$property['description'] = $label;
					}
				}

				// Handle nested objects if there's a foreign_table
				$foreignTable = $columnConfig['config']['foreign_table'] ?? '';
				if ($foreignTable !== '') {
					if (($property['type'] ?? '') === 'array' && isset($property['items'])) {
						$property['items'] = $this->enrichSchemaWithTca($property['items'], $foreignTable);
					} else {
						$property = $this->enrichSchemaWithTca($property, $foreignTable);
					}
				}
			}
		}

		return $schema;
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
	 * Generates a basic OpenAPI schema from a PHP example value
	 *
	 * @param mixed $example
	 * @param string $tableName
	 * @return array
	 */
	protected function generateSchemaFromExample(mixed $example, string $tableName = ''): array {
		if (is_string($example) && str_starts_with($example, 'schema:')) {
			$schemaStr = substr($example, 7);
			return $this->parseSchema(
				$schemaStr,
				array_merge(
					array_keys($this->schemaRegistry->getSchemas())
				)
			);
		}

		if (is_array($example)) {
			// Try to map global schema name to table name for TCA enrichment
			if ($tableName !== '' && !isset($GLOBALS['TCA'][$tableName])) {
				$mappedTableName = $this->schemaRegistry->getTableNameForSchema($tableName);
				if ($mappedTableName !== '') {
					$tableName = $mappedTableName;
				}
			}

			// Check if it's an associative array (object) or sequential (array)
			$isAssoc = count($example) > 0 && array_keys($example) !== range(0, count($example) - 1);
			if ($isAssoc) {
				$properties = [];
				$tca = ($tableName !== '' && isset($GLOBALS['TCA'][$tableName])) ? $GLOBALS['TCA'][$tableName] : NULL;
				$languageService = $this->endpointDiscoveryService->getLanguageService();

				foreach ($example as $key => $value) {
					$foreignTable = '';
					if ($key === 'data') {
						$foreignTable = $tableName;
					} elseif ($tca !== NULL && isset($tca['columns'][$key]['config']['foreign_table'])) {
						$foreignTable = $tca['columns'][$key]['config']['foreign_table'];
					}

					$schema = $this->generateSchemaFromExample($value, $foreignTable);

					// If the value was a schema placeholder, we might want to update the example value itself
					// to a more descriptive stub if it's not a $ref.
					// But more importantly, if it IS a $ref, the example should probably be updated too?
					// Actually, the example in $resp['content'][...]['example'] remains the original one.
					// If we want the Swagger UI to show a nice example, we should maybe resolve the placeholder in the example too.

					if ($tca !== NULL) {
						$tcaFieldName = $key;
						// Try to find the original field name if it was remapped
						// In generateSchemaFromExample, we don't have the remapped list easily,
						// but we can check if any field has a matching label or check renamed list.
						// Actually, we can check the TCA columns directly.
						if (!isset($tca['columns'][$key]) && isset($tca['columns'])) {
							// Check if it's one of our known remapped fields in Citypower
							$knownRemaps = [
								'tags' => 'offertags',
								'business_card_exclusive' => 'business_card',
								'text' => 'bodytext',
								'disturber' => 'tx_mask_citypower_slide_disturber',
								'fallback_link' => 'tx_mask_app_link',
								'app_link' => 'tx_mask_app_exclusive_link'
							];
							if (isset($knownRemaps[$key])) {
								$tcaFieldName = $knownRemaps[$key];
							}
						}

						if (isset($tca['columns'][$tcaFieldName]['label'])) {
							$label = (string) $languageService->sL($tca['columns'][$tcaFieldName]['label']);
							if ($label !== '') {
								$schema['description'] = $label;
							}
						}
					}

					$properties[$key] = $schema;
				}

				return [
					'type' => 'object',
					'properties' => $properties
				];
			}

			$items = ['type' => 'object'];
			if (count($example) > 0) {
				$items = $this->generateSchemaFromExample(reset($example), $tableName);
			}

			return [
				'type' => 'array',
				'items' => $items
			];
		}

		if (is_int($example)) {
			return ['type' => 'integer'];
		}
		if (is_float($example)) {
			return ['type' => 'number'];
		}
		if (is_bool($example)) {
			return ['type' => 'boolean'];
		}

		return ['type' => 'string'];
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
