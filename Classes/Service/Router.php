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

use FastRoute\Dispatcher;
use FastRoute\RouteCollector;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SGalinski\SgApiCore\Attribute\ApiLegacyMode;
use SGalinski\SgApiCore\Attribute\RequireFullTypoScript;
use SGalinski\SgApiCore\Attribute\RequireScopes;
use SGalinski\SgApiCore\Attribute\RequireUser;
use TYPO3\CMS\Core\Error\Http\AbstractServerErrorException;
use TYPO3\CMS\Core\Http\PropagateResponseException;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Frontend\Controller\TypoScriptFrontendController;
use function FastRoute\simpleDispatcher;

/**
 * Router service for the API
 */
class Router implements SingletonInterface {
	/**
	 * @var iterable
	 */
	protected iterable $controllers;

	/**
	 * @var EndpointDiscoveryService
	 */
	protected EndpointDiscoveryService $endpointDiscoveryService;

	/**
	 * @var RequestValidator
	 */
	protected RequestValidator $requestValidator;

	/**
	 * @var ResponseService
	 */
	protected ResponseService $responseService;

	/**
	 * @var LogService
	 */
	protected LogService $logService;

	/**
	 * @var array|null
	 */
	protected ?array $controllerInstances = NULL;

	/**
	 * @param iterable $controllers
	 * @param EndpointDiscoveryService $endpointDiscoveryService
	 * @param RequestValidator $requestValidator
	 * @param ResponseService $responseService
	 * @param LogService $logService
	 */
	public function __construct(
		iterable $controllers,
		EndpointDiscoveryService $endpointDiscoveryService,
		RequestValidator $requestValidator,
		ResponseService $responseService,
		LogService $logService
	) {
		$this->controllers = $controllers;
		$this->endpointDiscoveryService = $endpointDiscoveryService;
		$this->requestValidator = $requestValidator;
		$this->responseService = $responseService;
		$this->logService = $logService;
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
				$this->controllerInstances[get_class($controller)] = $controller;
			}
		}

		return $this->controllerInstances;
	}

	/**
	 * Dispatches the request to the matching controller action
	 *
	 * @param ServerRequestInterface $request
	 * @param string $apiId
	 * @param string $version
	 * @param string $path
	 * @param string|null $authMode
	 * @return ResponseInterface
	 * @throws \ReflectionException
	 * @throws AbstractServerErrorException
	 * @throws PropagateResponseException
	 */
	public function dispatch(
		ServerRequestInterface $request,
		string $apiId,
		string $version,
		string $path,
		?string $authMode = NULL
	): ResponseInterface {
		$endpoints = $this->endpointDiscoveryService->getAllEndpoints();

		// Filter endpoints by API ID, version and auth mode before creating the dispatcher
		$filteredEndpoints = [];
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
		}

		// Sort filtered endpoints again to ensure static routes are registered before variable routes.
		// This is necessary because some tests mock the discovery service and return unsorted endpoints.
		// It also doesn't hurt to be sure.
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

			// If both are static or both are variable, sort by path length (descending)
			// to ensure more specific routes are matched first.
			return strlen($pathB) <=> strlen($pathA);
		});

		$dispatcher = simpleDispatcher(function (RouteCollector $r) use ($filteredEndpoints) {
			foreach ($filteredEndpoints as $endpoint) {
				$r->addRoute($endpoint['methods'], $endpoint['path'], [
					'controller' => $endpoint['controller'],
					'action' => $endpoint['action'],
					'authMode' => $endpoint['authMode'] ?? NULL,
					'endpoint' => $endpoint,
					'resource' => $endpoint['resource'] ?? NULL
				]);
			}
		});

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
				$reflectionClass = new \ReflectionClass($handler['controller']);
				$reflectionMethod = $reflectionClass->getMethod($handler['action']);

				$effectiveAuthMode = (!empty($handler['authMode'])) ? $handler['authMode'] : ($authMode ?? 'public');
				$authContext = $request->getAttribute('api.auth');

				// If effective auth mode is not public, require authentication
				$isPublic = $effectiveAuthMode === 'public' || (is_array($effectiveAuthMode) && in_array(
					'public',
					$effectiveAuthMode,
					TRUE
				));
				if (!$isPublic && $authContext === NULL) {
					$authError = $request->getAttribute('api.authError');
					$detail = is_string($authError) && $authError !== '' ? $authError : 'Authentication required.';
					return $this->createErrorResponse($request, 'Unauthorized', $detail, 401);
				}

				// 3. Legacy Mode Enforcement
				$legacyModeAttributes = $reflectionMethod->getAttributes(ApiLegacyMode::class);
				if (count($legacyModeAttributes) === 0) {
					$legacyModeAttributes = $reflectionClass->getAttributes(ApiLegacyMode::class);
				}

				if (count($legacyModeAttributes) > 0) {
					$legacyMode = $legacyModeAttributes[0]->newInstance();
					$request = $request->withAttribute('api.legacyMode', $legacyMode);
				}

				// 4. TypoScript Enforcement
				$typoScriptAttributes = $reflectionMethod->getAttributes(RequireFullTypoScript::class);
				if (count($typoScriptAttributes) === 0) {
					$typoScriptAttributes = $reflectionClass->getAttributes(RequireFullTypoScript::class);
				}

				if (count($typoScriptAttributes) > 0) {
					$request = $request->withAttribute('api.requireFullTypoScript', TRUE);
					if (isset($GLOBALS['TSFE']) && $GLOBALS['TSFE'] instanceof TypoScriptFrontendController) {
						if ($GLOBALS['TSFE']->id <= 0) {
							$tenantContext = $request->getAttribute('api.tenant');
							if ($tenantContext instanceof \SGalinski\SgApiCore\Context\TenantContext) {
								$GLOBALS['TSFE']->id = $tenantContext->getSiteRootPageId();
							}
						}

						try {
							// Remove the stub to ensure getFromCache creates a fresh, full TypoScript object
							$request = $request->withoutAttribute('frontend.typoscript');
							if (method_exists($GLOBALS['TSFE'], 'getFromCache')) {
								/** @phpstan-ignore-next-line */
								$request = $GLOBALS['TSFE']->getFromCache($request);
							}
							$GLOBALS['TYPO3_REQUEST'] = $request;

							// Ensure TypoScript references are resolved (v12)
							if (class_exists(\TYPO3\CMS\Core\TypoScript\TemplateService::class)
								&& isset($GLOBALS['TSFE']->tmpl)
								&& $GLOBALS['TSFE']->tmpl instanceof \TYPO3\CMS\Core\TypoScript\TemplateService
							) {
								// Load TypoScript templates including constants before generating config
								if (isset($GLOBALS['TSFE']->rootLine) && is_array($GLOBALS['TSFE']->rootLine)) {
									/** @phpstan-ignore-next-line */
									$GLOBALS['TSFE']->tmpl->runThroughTemplates($GLOBALS['TSFE']->rootLine);
								}
								/** @phpstan-ignore-next-line */
								$GLOBALS['TSFE']->tmpl->generateConfig();

								// Ensure a config array is also populated in TSFE
								/** @phpstan-ignore-next-line */
								$GLOBALS['TSFE']->config = $GLOBALS['TSFE']->tmpl->setup['config.'] ?? [];
							}

							// Ensure the setup array is initialized and synchronized for TYPO3 13
							$frontendTypoScript = $request->getAttribute('frontend.typoscript');
							if ($frontendTypoScript instanceof \TYPO3\CMS\Core\TypoScript\FrontendTypoScript) {
								if (!$frontendTypoScript->hasSetup()) {
									if (class_exists(\TYPO3\CMS\Core\TypoScript\TemplateService::class)
										&& isset($GLOBALS['TSFE']->tmpl->setup)
										&& is_array($GLOBALS['TSFE']->tmpl->setup)
									) {
										/** @phpstan-ignore-next-line */
										$frontendTypoScript->setSetupArray($GLOBALS['TSFE']->tmpl->setup);
									}
								}
							}
						} catch (\Throwable $e) {
							$this->logService->logException($e, $request);
						}
					}
				}

				// 5. Authenticated User Enforcement
				$userAttributes = $reflectionMethod->getAttributes(RequireUser::class);
				if (count($userAttributes) > 0) {
					if ($authContext === NULL || $authContext->getUserId() === NULL) {
						return $this->createErrorResponse(
							$request,
							'Forbidden',
							'This endpoint requires a user login context.',
							403
						);
					}
				}

				// 6. Scope Enforcement
				$scopeAttributes = $reflectionMethod->getAttributes(RequireScopes::class);
				if (count($scopeAttributes) > 0) {
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
				$response = call_user_func_array([$controller, $handler['action']], $arguments);

				// Add Cache-Control headers if not already set by the controller, and an ApiCache attribute exists
				if ($apiCache instanceof \SGalinski\SgApiCore\Attribute\ApiCache &&
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
	 * Resolves and type-casts the arguments for the controller action
	 *
	 * @param ServerRequestInterface $request
	 * @param array $endpoint
	 * @param array $pathParams
	 * @return array
	 * @throws \ReflectionException
	 */
	protected function resolveArguments(ServerRequestInterface $request, array $endpoint, array $pathParams): array {
		$arguments = [$request];
		$queryParams = $request->getQueryParams();
		$bodyParams = $request->getParsedBody();

		// We need to match the action's parameter names and order
		$reflectionClass = new \ReflectionClass($endpoint['controller']);
		$reflectionMethod = $reflectionClass->getMethod($endpoint['action']);

		foreach ($reflectionMethod->getParameters() as $index => $parameter) {
			if ($index === 0) {
				// The first argument is always the request
				continue;
			}

			$name = $parameter->getName();
			$value = $pathParams[$name] ?? $queryParams[$name] ?? $bodyParams[$name] ?? NULL;

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
					'bool', 'boolean' => in_array($value, ['1', 'true', 1, TRUE, 'on', 'yes'], TRUE),
					'array' => is_array($value) ? $value : [$value],
					default => $value,
				};
			}

			$arguments[] = $value;
		}

		return $arguments;
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
