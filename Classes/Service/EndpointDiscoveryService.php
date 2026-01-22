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
use SGalinski\SgApiCore\Attribute\ApiCache;
use SGalinski\SgApiCore\Attribute\ApiEndpoint;
use SGalinski\SgApiCore\Attribute\ApiPathParam;
use SGalinski\SgApiCore\Attribute\ApiQueryParam;
use SGalinski\SgApiCore\Attribute\ApiResponse;
use SGalinski\SgApiCore\Attribute\ApiRoute;
use SGalinski\SgApiCore\Attribute\RequireScopes;
use SGalinski\SgApiCore\Controller\ResourceController;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\SingletonInterface;

/**
 * Service to discover all registered API endpoints
 */
class EndpointDiscoveryService implements SingletonInterface {
	/**
	 * @var iterable
	 */
	protected iterable $controllers;

	/**
	 * @var array|null
	 */
	protected ?array $controllerClasses = NULL;

	/**
	 * @var array|null
	 */
	protected ?array $endpoints = NULL;

	/**
	 * @var FrontendInterface
	 */
	protected FrontendInterface $cache;

	/**
	 * @param iterable $controllers
	 * @param ResourceRegistry $resourceRegistry
	 * @param CacheManager $cacheManager
	 */
	public function __construct(
		iterable $controllers,
		protected ResourceRegistry $resourceRegistry,
		CacheManager $cacheManager
	) {
		$this->controllers = $controllers;
		$this->cache = $cacheManager->getCache('sg_apicore_discovery');
	}

	/**
	 * Returns all discovered endpoints
	 *
	 * @return array
	 * @throws \ReflectionException
	 */
	public function getAllEndpoints(): array {
		if ($this->endpoints !== NULL) {
			return $this->endpoints;
		}

		$cacheKey = 'all_endpoints';
		$cachedEndpoints = $this->cache->get($cacheKey);
		if (is_array($cachedEndpoints)) {
			$this->endpoints = $cachedEndpoints;
			return $this->endpoints;
		}

		$endpoints = [];
		foreach ($this->getControllerClasses() as $controllerClass) {
			$reflectionClass = new \ReflectionClass($controllerClass);
			foreach ($reflectionClass->getMethods() as $method) {
				$routeAttributes = $method->getAttributes(ApiRoute::class);
				foreach ($routeAttributes as $routeAttr) {
					/** @var ApiRoute $route */
					$route = $routeAttr->newInstance();

					$endpointAttr = $method->getAttributes(ApiEndpoint::class)[0] ?? NULL;
					/** @var ApiEndpoint|null $endpoint */
					$endpoint = $endpointAttr?->newInstance();

					$scopeAttr = $method->getAttributes(RequireScopes::class)[0] ?? NULL;
					/** @var RequireScopes|null $requireScopes */
					$requireScopes = $scopeAttr?->newInstance();

					$bodyParams = [];
					foreach ($method->getAttributes(ApiBodyParam::class) as $attr) {
						$bodyParams[] = $attr->newInstance();
					}

					$queryParams = [];
					foreach ($method->getAttributes(ApiQueryParam::class) as $attr) {
						$queryParams[] = $attr->newInstance();
					}

					$pathParams = [];
					foreach ($method->getAttributes(ApiPathParam::class) as $attr) {
						$pathParams[] = $attr->newInstance();
					}

					$responses = [];
					foreach ($method->getAttributes(ApiResponse::class) as $attr) {
						$responses[] = $attr->newInstance();
					}

					$apiCache = NULL;
					$cacheAttr = $method->getAttributes(ApiCache::class);
					if (count($cacheAttr) > 0) {
						$apiCache = $cacheAttr[0]->newInstance();
					}

					$tags = $endpoint?->tags ?? [];
					if (empty($tags)) {
						$pathSegments = explode('/', trim($route->path, '/'));
						if (count($pathSegments) > 0) {
							$tags = [$pathSegments[0]];
						}
					}

					$endpoints[] = [
						'apiId' => is_array($route->apiId) ?
							$route->apiId : ($route->apiId !== NULL ? [$route->apiId] : []),
						'version' => is_array($route->version) ?
							$route->version : ($route->version !== NULL ? [$route->version] : []),
						'path' => $route->path,
						'methods' => $route->methods,
						'authMode' => is_array($route->authMode) ?
							$route->authMode : ($route->authMode !== NULL ? [$route->authMode] : []),
						'summary' => $endpoint?->summary ?? $method->getName(),
						'description' => $endpoint?->description ?? '',
						'tags' => $tags,
						'scopes' => $requireScopes?->scopes ?? [],
						'bodyParams' => $bodyParams,
						'queryParams' => $queryParams,
						'pathParams' => $pathParams,
						'responses' => $responses,
						'apiCache' => $apiCache,
						'controller' => $controllerClass,
						'action' => $method->getName()
					];
				}
			}
		}

		// Add resource-based endpoints
		foreach ($this->resourceRegistry->getResources() as $apiId => $resources) {
			foreach ($resources as $config) {
				/** @noinspection SlowArrayOperationsInLoopInspection */
				$endpoints = array_merge($endpoints, $this->generateResourceEndpoints((string) $apiId, $config));
			}
		}

		// Sort endpoints to ensure static routes are registered before variable routes.
		// This prevents "shadowing" errors in fast-route and improves performance in Router::dispatch
		usort($endpoints, static function ($a, $b) {
			$pathA = $a['path'];
			$pathB = $b['path'];

			$isStaticA = !str_contains($pathA, '{');
			$isStaticB = !str_contains($pathB, '{');

			if ($isStaticA && !$isStaticB) {
				return -1;
			}
			if (!$isStaticA && $isStaticB) {
				return 1;
			}

			// If both are static or both are variable, sort by path length (descending)
			// to ensure more specific routes are matched first.
			return strlen($pathB) <=> strlen($pathA);
		});

		$this->endpoints = $endpoints;
		$this->cache->set($cacheKey, $endpoints);
		return $endpoints;
	}

	/**
	 * Generates CRUD endpoints for a resource
	 *
	 * @param string $apiId
	 * @param array $config
	 * @return array
	 */
	protected function generateResourceEndpoints(string $apiId, array $config): array {
		$endpoints = [];
		$basePath = '/' . ltrim($config['basePath'], '/');
		$tableName = $config['table'];
		$allowedOps = $config['allowedOperations'];
		$scopes = $config['requiredScopes'] ?? [];
		$writeFields = $config['writeFields'] ?? [];
		$readFields = $config['readFields'] ?? [];
		$tags = $config['tags'] ?? [];
		if (empty($tags)) {
			$tags = [$tableName];
		}
		$tca = $GLOBALS['TCA'][$tableName] ?? [];
		$availableFields = array_keys($tca['columns'] ?? []);
		$writeFieldCandidates = $writeFields;
		if (empty($writeFieldCandidates)) {
			$writeFieldCandidates = !empty($readFields) ? $readFields : $availableFields;
		}
		$writeFieldCandidates = array_values(array_filter(
			$writeFieldCandidates,
			static fn (string $fieldName): bool => $fieldName !== 'uid'
		));
		$allFields = array_unique(array_merge($readFields, $writeFieldCandidates));

		// Determine field metadata from TCA
		$fieldMetadata = [];
		foreach ($allFields as $fieldName) {
			$type = 'string';
			$description = 'Field: ' . $fieldName;
			$required = FALSE;
			$pattern = NULL;
			$min = NULL;
			$max = NULL;
			if (isset($tca['columns'][$fieldName]['config'])) {
				$colConfig = $tca['columns'][$fieldName]['config'];
				$tcaType = $colConfig['type'] ?? '';
				$eval = $colConfig['eval'] ?? '';
				switch ($tcaType) {
					case 'check':
						$type = 'boolean';
						break;
					case 'number':
						$type = str_contains($colConfig['format'] ?? '', 'decimal') ? 'float' : 'integer';
						if (isset($colConfig['range']['lower'])) {
							$min = (float) $colConfig['range']['lower'];
						}
						if (isset($colConfig['range']['upper'])) {
							$max = (float) $colConfig['range']['upper'];
						}
						break;
					case 'input':
						if (str_contains($eval, 'int')) {
							$type = 'integer';
						} elseif (str_contains($eval, 'double2') || str_contains($eval, 'num')) {
							$type = 'float';
						}

						if (str_contains($eval, 'email')) {
							$pattern = '/^[a-zA-Z0-9+_.-]+@[a-zA-Z0-9.-]+$/';
						}
						break;
					case 'select':
						$type = 'string';
						if (isset($colConfig['items'])) {
							$description .= ' (Possible values: ' . implode(
								', ',
								array_map(static fn ($item) => $item[1] ?? $item['value'] ?? '', $colConfig['items'])
							) . ')';
						}
						break;
				}

				if (str_contains($eval, 'required')) {
					$required = TRUE;
				}

				if (isset($tca['columns'][$fieldName]['label'])) {
					$description = $tca['columns'][$fieldName]['label'];
				}
			}
			$fieldMetadata[$fieldName] = [
				'name' => $fieldName,
				'type' => $type,
				'description' => $description,
				'required' => $required,
				'pattern' => $pattern,
				'min' => $min,
				'max' => $max
			];
		}

		// Body params for write operations
		$bodyParams = [];
		foreach ($writeFieldCandidates as $fieldName) {
			if (isset($fieldMetadata[$fieldName])) {
				$meta = $fieldMetadata[$fieldName];
				$bodyParams[] = new ApiBodyParam(
					name: $meta['name'],
					type: $meta['type'],
					required: $meta['required'],
					description: $meta['description'],
					pattern: $meta['pattern'],
					min: $meta['min'],
					max: $meta['max']
				);
			}
		}

		$resourceInfo = $config;
		$resourceInfo['fieldMetadata'] = $fieldMetadata;

		// List
		if (in_array('list', $allowedOps, TRUE)) {
			$endpoints[] = [
				'apiId' => [$apiId],
				'version' => [], // All versions of this API
				'path' => $basePath,
				'methods' => ['GET'],
				'authMode' => [], // Default
				'summary' => 'List ' . $tableName,
				'description' => 'Returns a paginated list of ' . $tableName . ' resources.',
				'tags' => $tags,
				'scopes' => $scopes['list'] ?? [],
				'bodyParams' => [],
				'queryParams' => [
					new ApiQueryParam(
						name: 'page',
						type: 'integer',
						required: FALSE,
						description: 'Page number (1-based)'
					),
					new ApiQueryParam(
						name: 'perPage',
						type: 'integer',
						required: FALSE,
						description: 'Items per page',
						example: 50
					),
					new ApiQueryParam(
						name: 'sort',
						type: 'string',
						required: FALSE,
						description: 'Sort field (prefix with - for DESC)'
					),
					new ApiQueryParam(
						name: 'filter',
						type: 'array',
						required: FALSE,
						description: 'Filter by fields: filter[field]=value'
					),
					new ApiQueryParam(
						name: 'skipCount',
						type: 'boolean',
						required: FALSE,
						description: 'If set to true, the total record count for pagination is skipped for better performance.'
					),
				],
				'pathParams' => [],
				'responses' => [
					new ApiResponse(status: 200, description: 'Success', schema: $tableName . '[]')
				],
				'apiCache' => new ApiCache(tags: [$tableName]),
				'controller' => ResourceController::class,
				'action' => 'listAction',
				'resource' => $resourceInfo
			];
		}

		// Get
		if (in_array('get', $allowedOps, TRUE)) {
			$endpoints[] = [
				'apiId' => [$apiId],
				'version' => [],
				'path' => $basePath . '/{id}',
				'methods' => ['GET'],
				'authMode' => [],
				'summary' => 'Get ' . $tableName,
				'description' => 'Returns a single ' . $tableName . ' resource by ID.',
				'tags' => $tags,
				'scopes' => $scopes['get'] ?? [],
				'bodyParams' => [],
				'queryParams' => [],
				'pathParams' => [
					new ApiPathParam(name: 'id', type: 'string', description: 'The resource ID')
				],
				'responses' => [
					new ApiResponse(status: 200, description: 'Success', schema: $tableName),
					new ApiResponse(status: 404, description: 'Not Found')
				],
				'apiCache' => new ApiCache(tags: [$tableName]),
				'controller' => ResourceController::class,
				'action' => 'getAction',
				'resource' => $resourceInfo
			];
		}

		// Create (POST)
		if (in_array('create', $allowedOps, TRUE)) {
			$endpoints[] = [
				'apiId' => [$apiId],
				'version' => [],
				'path' => $basePath,
				'methods' => ['POST'],
				'authMode' => [],
				'summary' => 'Create ' . $tableName,
				'description' => 'Creates a new ' . $tableName . ' resource.',
				'tags' => $tags,
				'scopes' => $scopes['create'] ?? [],
				'bodyParams' => $bodyParams,
				'queryParams' => [],
				'pathParams' => [],
				'responses' => [
					new ApiResponse(status: 201, description: 'Created', schema: $tableName)
				],
				'apiCache' => new ApiCache(tags: [$tableName]),
				'controller' => ResourceController::class,
				'action' => 'createAction',
				'resource' => $resourceInfo
			];
		}

		// Update (PATCH)
		if (in_array('update', $allowedOps, TRUE)) {
			$endpoints[] = [
				'apiId' => [$apiId],
				'version' => [],
				'path' => $basePath . '/{id}',
				'methods' => ['PATCH'],
				'authMode' => [],
				'summary' => 'Update ' . $tableName,
				'description' => 'Updates an existing ' . $tableName . ' resource.',
				'tags' => $tags,
				'scopes' => $scopes['update'] ?? [],
				'bodyParams' => $bodyParams,
				'queryParams' => [],
				'pathParams' => [
					new ApiPathParam(name: 'id', type: 'string', description: 'The resource ID')
				],
				'responses' => [
					new ApiResponse(status: 200, description: 'Updated', schema: $tableName),
					new ApiResponse(status: 404, description: 'Not Found')
				],
				'apiCache' => new ApiCache(tags: [$tableName]),
				'controller' => ResourceController::class,
				'action' => 'updateAction',
				'resource' => $resourceInfo
			];
		}

		// Delete
		if (in_array('delete', $allowedOps, TRUE)) {
			$endpoints[] = [
				'apiId' => [$apiId],
				'version' => [],
				'path' => $basePath . '/{id}',
				'methods' => ['DELETE'],
				'authMode' => [],
				'summary' => 'Delete ' . $tableName,
				'description' => 'Deletes an existing ' . $tableName . ' resource.',
				'tags' => $tags,
				'scopes' => $scopes['delete'] ?? [],
				'bodyParams' => [],
				'queryParams' => [],
				'pathParams' => [
					new ApiPathParam(name: 'id', type: 'string', description: 'The resource ID')
				],
				'responses' => [
					new ApiResponse(status: 204, description: 'Deleted (no content)'),
					new ApiResponse(status: 404, description: 'Not Found')
				],
				'apiCache' => new ApiCache(tags: [$tableName]),
				'controller' => ResourceController::class,
				'action' => 'deleteAction',
				'resource' => $resourceInfo
			];
		}

		return $endpoints;
	}

	/**
	 * Returns discovered endpoints for a specific API and version
	 *
	 * @param string $apiId
	 * @param string|null $version
	 * @return array
	 * @throws \ReflectionException
	 */
	public function getEndpointsForApi(string $apiId, ?string $version = NULL): array {
		$endpoints = $this->getAllEndpoints();
		return array_filter($endpoints, static function (array $endpoint) use ($apiId, $version) {
			$apiMatch = count($endpoint['apiId']) === 0 || in_array($apiId, $endpoint['apiId'], TRUE);
			$versionMatch = $version === NULL || count($endpoint['version']) === 0 || in_array(
				$version,
				$endpoint['version'],
				TRUE
			);
			return $apiMatch && $versionMatch;
		});
	}

	/**
	 * Returns the class names of all registered controllers
	 *
	 * @return array
	 * @throws \ReflectionException
	 */
	protected function getControllerClasses(): array {
		if ($this->controllerClasses === NULL) {
			$this->controllerClasses = [];
			foreach ($this->controllers as $controller) {
				$className = get_class($controller);
				if (str_contains($className, '@anonymous')) {
					$reflectionClass = new \ReflectionClass($controller);
					$className = $reflectionClass->getName();
				}
				$this->controllerClasses[] = $className;
			}
		}

		return $this->controllerClasses;
	}
}
