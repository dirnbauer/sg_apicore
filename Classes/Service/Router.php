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

use FastRoute\Dispatcher;
use FastRoute\RouteCollector;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ReflectionClass;
use ReflectionException;
use SGalinski\SgApiCore\Attribute\ApiCache;
use SGalinski\SgApiCore\Attribute\ApiLegacyMode;
use SGalinski\SgApiCore\Attribute\RequireScopes;
use SGalinski\SgApiCore\Attribute\RequireUser;
use TYPO3\CMS\Core\Error\Http\AbstractServerErrorException;
use TYPO3\CMS\Core\Http\PropagateResponseException;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use function FastRoute\cachedDispatcher;

/**
 * Router service for the API
 */
class Router implements SingletonInterface {
	/**
	 * @var CachePathService
	 */
	protected CachePathService $cachePathService;

	/**
	 * @var array|null
	 */
	protected ?array $controllerInstances = NULL;
	/**
	 * @param iterable $controllers
	 * @param EndpointDiscoveryService $endpointDiscoveryService
	 * @param RequestValidator $requestValidator
	 * @param ResponseService $responseService
	 * @param CachePathService|null $cachePathService
	 */
	public function __construct(
		protected iterable $controllers,
		protected EndpointDiscoveryService $endpointDiscoveryService,
		protected RequestValidator $requestValidator,
		protected ResponseService $responseService,
		?CachePathService $cachePathService = NULL
	) {
		$this->cachePathService = $cachePathService ?? new CachePathService();
	}

	/**
	 * @param ServerRequestInterface $request
	 * @param string $apiId
	 * @param string $version
	 * @param string $path
	 * @param mixed $authMode
	 * @return ResponseInterface
	 * @throws ReflectionException
	 * @throws AbstractServerErrorException
	 * @throws PropagateResponseException
	 */
	public function dispatch(
		ServerRequestInterface $request,
		string $apiId,
		string $version,
		string $path,
		mixed $authMode = NULL
	): ResponseInterface {
		$path = $path !== '/' ? rtrim($path, '/') : $path;
		if ($path === '') {
			$path = '/';
		}

		$tenantContext = $request->getAttribute('api.tenant');
		$tenantId = $tenantContext?->getTenantId() ?? '';

		$filteredEndpoints = $this->getFilteredEndpoints($apiId, $version, $authMode, $tenantId);
		$dispatcher = $this->createDispatcher($filteredEndpoints, $apiId, $version, $authMode, $tenantId);

		$routeInfo = $dispatcher->dispatch($request->getMethod(), $path);

		switch ($routeInfo[0]) {
			case Dispatcher::NOT_FOUND:
				return $this->createErrorResponse($request, 'Not Found', 'The requested route does not exist.', 404);
			case Dispatcher::METHOD_NOT_ALLOWED:
				return $this->createErrorResponse(
					$request,
					'Method Not Allowed',
					'The requested method is not allowed for this route.',
					405
				);
			case Dispatcher::FOUND:
				$handler = $routeInfo[1];
				/** @noinspection MultiAssignmentUsageInspection */
				$vars = $routeInfo[2];

				if (isset($handler['resource'])) {
					$request = $request->withAttribute('api.resource', $handler['resource']);
				}
				if (!empty($handler['endpoint']['requireFullTypoScript'])) {
					$request = $request->withAttribute('api.requireFullTypoScript', TRUE);
				}

				// 1. Validation Enforcement
				if (isset($handler['endpoint'])) {
					$validationErrors = $this->requestValidator->validate($request, $handler['endpoint'], $vars);
					if ($validationErrors !== NULL) {
						return $this->createErrorResponse(
							$request,
							'Validation Failed',
							'The request parameters are invalid.',
							400,
							['errors' => $validationErrors]
						);
					}
				}

				// 2. Authentication Enforcement
				$reflectionClass = new ReflectionClass($handler['controller']);
				$reflectionMethod = $reflectionClass->getMethod($handler['action']);

				$effectiveAuthMode = (!empty($handler['authMode'])) ? $handler['authMode'] : ($authMode ?? 'public');
				$authContext = $request->getAttribute('api.auth');

				// If effective auth mode is not public, require authentication
				$isPublic = $effectiveAuthMode === 'public' || (\is_array($effectiveAuthMode) && \in_array(
					'public',
					$effectiveAuthMode,
					TRUE
				));
				if (!$isPublic && $authContext === NULL) {
					$authError = $request->getAttribute('api.authError');
					$detail = \is_string($authError) && $authError !== '' ? $authError : 'Authentication required.';
					return $this->createErrorResponse($request, 'Unauthorized', $detail, 401);
				}

				// 3. Legacy Mode Enforcement
				$legacyModeAttributes = $reflectionMethod->getAttributes(ApiLegacyMode::class);
				if (\count($legacyModeAttributes) === 0) {
					$legacyModeAttributes = $reflectionClass->getAttributes(ApiLegacyMode::class);
				}

				if (\count($legacyModeAttributes) > 0) {
					$legacyMode = $legacyModeAttributes[0]->newInstance();
					$request = $request->withAttribute('api.legacyMode', $legacyMode);
				}

				// 4. Authenticated User Enforcement
				$userAttributes = $reflectionMethod->getAttributes(RequireUser::class);
				if (\count($userAttributes) > 0) {
					if ($authContext === NULL || $authContext->getUserId() === NULL) {
						return $this->createErrorResponse($request, 'Forbidden', 'This endpoint requires a user login context.', 403);
					}
				}

				// 5. Scope Enforcement
				$scopeAttributes = $reflectionMethod->getAttributes(RequireScopes::class);
				if (\count($scopeAttributes) > 0) {
					/** @var RequireScopes $requireScopes */
					$requireScopes = $scopeAttributes[0]->newInstance();
					foreach ($requireScopes->scopes as $scope) {
						if ($authContext === NULL || !$authContext->hasScope($scope)) {
							return $this->createErrorResponse(
								$request,
								'Forbidden',
								'You do not have the required scope: ' . $scope,
								403
							);
						}
					}
				}

				$controller = $this->getControllerInstances()[$handler['controller']];
				$arguments = $this->resolveArguments($request, $handler['endpoint'], $vars);

				$apiCache = $handler['endpoint']['apiCache'] ?? NULL;
				$response = \call_user_func_array([$controller, $handler['action']], $arguments);

				// Add Cache-Control headers if not already set by the controller, and an ApiCache attribute exists
				if ($apiCache instanceof ApiCache &&
					!$response->hasHeader('Cache-Control')
				) {
					if ($apiCache->enabled && $apiCache->lifetime > 0) {
						$response = $response->withHeader('Cache-Control', 'public, max-age=' . $apiCache->lifetime);
					} elseif (!$apiCache->enabled) {
						$response = $response->withHeader('Cache-Control', 'no-store, no-cache, must-revalidate');
					}
				}

				return $response;
		}

		return $this->createErrorResponse($request, 'Internal Server Error', 'An unexpected error occurred.', 500);
	}

	/**
	 * Returns a matched endpoint for the given request data
	 *
	 * @param ServerRequestInterface $request
	 * @param string $apiId
	 * @param string $version
	 * @param string $path
	 * @param string|null $authMode
	 * @return array|null
	 * @throws ReflectionException
	 */
	public function matchEndpoint(
		ServerRequestInterface $request,
		string $apiId,
		string $version,
		string $path,
		?string $authMode = NULL
	): ?array {
		$path = $path !== '/' ? rtrim($path, '/') : $path;
		if ($path === '') {
			$path = '/';
		}

		$tenantContext = $request->getAttribute('api.tenant');
		$tenantId = $tenantContext?->getTenantId() ?? '';

		$filteredEndpoints = $this->getFilteredEndpoints($apiId, $version, $authMode, $tenantId);
		if (\count($filteredEndpoints) === 0) {
			return NULL;
		}

		$dispatcher = $this->createDispatcher($filteredEndpoints, $apiId, $version, $authMode, $tenantId);
		$routeInfo = $dispatcher->dispatch($request->getMethod(), $path);
		if ($routeInfo[0] !== Dispatcher::FOUND) {
			return NULL;
		}

		return $routeInfo[1];
	}

	/**
	 * Returns all registered controller instances
	 *
	 * @return array
	 */
	protected function getControllerInstances(): array {
		if ($this->controllerInstances === NULL) {
			$this->controllerInstances = [];
			foreach ($this->controllers as $controller) {
				$this->controllerInstances[\get_class($controller)] = $controller;
			}
		}

		return $this->controllerInstances;
	}

	/**
	 * Resolves and type-casts the arguments for the controller action
	 *
	 * @param ServerRequestInterface $request
	 * @param array $endpoint
	 * @param array $pathParams
	 * @return array
	 * @throws ReflectionException
	 */
	protected function resolveArguments(ServerRequestInterface $request, array $endpoint, array $pathParams): array {
		$arguments = [$request];
		$queryParams = $request->getQueryParams();
		$bodyParams = $request->getParsedBody();

		// We need to match the action's parameter names and order
		$reflectionClass = new ReflectionClass($endpoint['controller']);
		$reflectionMethod = $reflectionClass->getMethod($endpoint['action']);

		foreach ($reflectionMethod->getParameters() as $index => $parameter) {
			if ($index === 0) {
				// The first argument is always the request
				continue;
			}

			$name = $parameter->getName();
			$value = $pathParams[$name] ?? $queryParams[$name] ?? $queryParams[$name . '[]'] ?? $bodyParams[$name] ?? NULL;

			if ($value === NULL && $parameter->isDefaultValueAvailable()) {
				$value = $parameter->getDefaultValue();
			}

			$paramMetadata = NULL;
			$allMetadata = array_merge(
				$endpoint['pathParams'] ?? [],
				$endpoint['queryParams'] ?? [],
				$endpoint['bodyParams'] ?? []
			);
			foreach ($allMetadata as $meta) {
				if ($meta->name === $name) {
					$paramMetadata = $meta;
					break;
				}
			}

			if ($paramMetadata !== NULL && $value !== NULL) {
				$value = match (strtolower($paramMetadata->type)) {
					'int', 'integer' => (int) $value,
					'float', 'double', 'number' => (float) $value,
					'bool', 'boolean' => \in_array($value, ['1', 'true', 1, TRUE, 'on', 'yes'], TRUE),
					'array' => \is_array($value) ? $value : GeneralUtility::trimExplode(',', (string) $value, TRUE),
					default => $value,
				};
			}

			$arguments[] = $value;
		}

		return $arguments;
	}

	/**
	 * @param string $apiId
	 * @param string $version
	 * @param string|null $authMode
	 * @param string $tenantId
	 * @return array
	 * @throws ReflectionException
	 */
	protected function getFilteredEndpoints(
		string $apiId,
		string $version,
		?string $authMode,
		string $tenantId = ''
	): array {
		$filteredEndpoints = $this->endpointDiscoveryService->getEndpointsForApi($apiId, $version, $authMode, $tenantId);

		usort($filteredEndpoints, static function ($a, $b) {
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

			return \strlen($pathB) <=> \strlen($pathA);
		});

		return $filteredEndpoints;
	}

	/**
	 * @param array $filteredEndpoints
	 * @param string $apiId
	 * @param string $version
	 * @param mixed $authMode
	 * @return Dispatcher
	 */
	protected function createDispatcher(
		array $filteredEndpoints,
		string $apiId,
		string $version,
		mixed $authMode,
		string $tenantId = ''
	): Dispatcher {
		$cacheDirectory = $this->cachePathService->getFastRouteCacheDirectory();

		$authKey = \is_array($authMode) ? implode(',', $authMode) : (string) $authMode;
		$endpointSignature = sha1(serialize($filteredEndpoints));
		$discoverySignature = $this->endpointDiscoveryService->getDiscoverySignature();
		$cacheFile = $cacheDirectory . '/routes_' . md5(
			$apiId . '|' . $version . '|' . $authKey . '|' . $tenantId . '|' . $discoverySignature . '|' . $endpointSignature
		) . '.php';

		return cachedDispatcher(function (RouteCollector $r) use ($filteredEndpoints) {
			foreach ($filteredEndpoints as $endpoint) {
				$r->addRoute($endpoint['methods'], $endpoint['path'], [
					'controller' => $endpoint['controller'],
					'action' => $endpoint['action'],
					'authMode' => $endpoint['authMode'] ?? NULL,
					'endpoint' => $endpoint,
					'resource' => $endpoint['resource'] ?? NULL,
				]);
			}
		}, ['cacheFile' => $cacheFile]);
	}

	/**
	 * Creates a Problem JSON error response (RFC 7807)
	 *
	 * @param ServerRequestInterface $request
	 * @param string $title
	 * @param string $detail
	 * @param int $status
	 * @param array $additionalData
	 * @return ResponseInterface
	 */
	protected function createErrorResponse(
		ServerRequestInterface $request,
		string $title,
		string $detail,
		int $status,
		array $additionalData = []
	): ResponseInterface {
		$legacyMode = $request->getAttribute('api.legacyMode');
		if ($legacyMode === NULL && ($request->getAttribute('api.isLegacy') ||
				$request->getAttribute('api.id') === 'legacy')
		) {
			$legacyMode = new ApiLegacyMode();
		}

		return $this->responseService->createErrorResponse(
			$title,
			$detail,
			$status,
			additionalData: $additionalData,
			legacyMode: $legacyMode
		);
	}
}
