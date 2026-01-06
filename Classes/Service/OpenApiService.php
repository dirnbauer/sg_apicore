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
	 * @var iterable
	 */
	protected iterable $controllers;

	/**
	 * @var array|null
	 */
	protected ?array $controllerClasses = NULL;

	/**
	 * @var ApiRegistry
	 */
	protected ApiRegistry $apiRegistry;

	/**
	 * @var ExtensionConfiguration
	 */
	protected ExtensionConfiguration $extensionConfiguration;

	/**
	 * @param iterable $controllers
	 * @param ApiRegistry $apiRegistry
	 * @param ExtensionConfiguration $extensionConfiguration
	 */
	public function __construct(
		iterable $controllers,
		ApiRegistry $apiRegistry,
		ExtensionConfiguration $extensionConfiguration
	) {
		$this->controllers = $controllers;
		$this->apiRegistry = $apiRegistry;
		$this->extensionConfiguration = $extensionConfiguration;
	}

	/**
	 * Returns the class names of all registered controllers
	 *
	 * @return array
	 */
	protected function getControllerClasses(): array {
		if ($this->controllerClasses === NULL) {
			$this->controllerClasses = [];
			foreach ($this->controllers as $controller) {
				$this->controllerClasses[] = get_class($controller);
			}
		}

		return $this->controllerClasses;
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

		$spec = [
			'openapi' => '3.0.3',
			'info' => [
				'title' => 'API: ' . $apiId . ' (v' . $version . ')',
				'version' => $version,
				'description' => 'Automatically generated OpenAPI specification for the ' . $apiId . ' API.'
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

		foreach ($this->getControllerClasses() as $controllerClass) {
			$reflectionClass = new \ReflectionClass($controllerClass);
			foreach ($reflectionClass->getMethods() as $method) {
				$routeAttributes = $method->getAttributes(ApiRoute::class);
				foreach ($routeAttributes as $routeAttr) {
					/** @var ApiRoute $route */
					$route = $routeAttr->newInstance();

					// Check if this route belongs to the requested apiId, version and authMode
					if ($route->apiId !== NULL) {
						$apiIds = is_array($route->apiId) ? $route->apiId : [$route->apiId];
						if (!in_array($apiId, $apiIds, TRUE)) {
							continue;
						}
					}
					if ($route->version !== NULL) {
						$versions = is_array($route->version) ? $route->version : [$route->version];
						if (!in_array($version, $versions, TRUE)) {
							continue;
						}
					}
					if ($route->authMode !== NULL) {
						$authModes = is_array($route->authMode) ? $route->authMode : [$route->authMode];
						if (!in_array($authMode, $authModes, TRUE)) {
							continue;
						}
					}

					$this->addRouteToSpec($spec, $method, $route);
				}
			}
		}

		return $spec;
	}

	/**
	 * Adds a route and its metadata to the OpenAPI spec
	 *
	 * @param array &$spec
	 * @param \ReflectionMethod $method
	 * @param ApiRoute $route
	 */
	protected function addRouteToSpec(array &$spec, \ReflectionMethod $method, ApiRoute $route): void {
		$path = $route->path;
		// OpenAPI paths must start with a slash and shouldn't have trailing slashes
		$path = '/' . ltrim($path, '/');

		if (!isset($spec['paths'][$path])) {
			$spec['paths'][$path] = [];
		}

		$endpointAttr = $method->getAttributes(ApiEndpoint::class)[0] ?? NULL;
		/** @var ApiEndpoint|null $endpoint */
		$endpoint = $endpointAttr?->newInstance();

		$scopeAttr = $method->getAttributes(RequireScopes::class)[0] ?? NULL;
		/** @var RequireScopes|null $requireScopes */
		$requireScopes = $scopeAttr?->newInstance();

		$operation = [
			'summary' => $endpoint?->summary ?? $method->getName(),
			'description' => $endpoint?->description ?? '',
			'tags' => $endpoint?->tags ?? [],
			'responses' => []
		];

		if ($requireScopes && count($requireScopes->scopes) > 0) {
			$operation['description'] .= "\n\n**Required Scopes:** " . implode(', ', $requireScopes->scopes);
		}

		// Parameters (Query, Path)
		$operation['parameters'] = [];
		foreach ($method->getAttributes(ApiQueryParam::class) as $attr) {
			/** @var ApiQueryParam $param */
			$param = $attr->newInstance();
			$operation['parameters'][] = [
				'name' => $param->name,
				'in' => 'query',
				'required' => $param->required,
				'description' => $param->description,
				'schema' => ['type' => $this->mapPhpTypeToOpenApi($param->type)]
			];
		}
		foreach ($method->getAttributes(ApiPathParam::class) as $attr) {
			/** @var ApiPathParam $param */
			$param = $attr->newInstance();
			$operation['parameters'][] = [
				'name' => $param->name,
				'in' => 'path',
				'required' => TRUE,
				'description' => $param->description,
				'schema' => ['type' => $this->mapPhpTypeToOpenApi($param->type)]
			];
		}

		// Responses
		foreach ($method->getAttributes(ApiResponse::class) as $attr) {
			/** @var ApiResponse $response */
			$response = $attr->newInstance();
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

		foreach ($route->methods as $httpMethod) {
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
