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
use SGalinski\SgApiCore\Attribute\ApiRoute;
use SGalinski\SgApiCore\Attribute\RequireScopes;
use SGalinski\SgApiCore\Attribute\RequireUser;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;
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
	 * @var array|null
	 */
	protected ?array $controllerInstances = NULL;

	/**
	 * @param iterable $controllers
	 * @param EndpointDiscoveryService $endpointDiscoveryService
	 * @param RequestValidator $requestValidator
	 */
	public function __construct(
		iterable $controllers,
		EndpointDiscoveryService $endpointDiscoveryService,
		RequestValidator $requestValidator
	) {
		$this->controllers = $controllers;
		$this->endpointDiscoveryService = $endpointDiscoveryService;
		$this->requestValidator = $requestValidator;
	}

	/**
	 * @param array $controllers
	 */
	public function setControllers(array $controllers): void {
		$this->controllerInstances = [];
		foreach ($controllers as $controller) {
			if (is_string($controller)) {
				$controller = GeneralUtility::makeInstance($controller);
			}
			if ($controller) {
				$this->controllerInstances[get_class($controller)] = $controller;
			}
		}
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
	 */
	public function dispatch(
		ServerRequestInterface $request,
		string $apiId,
		string $version,
		string $path,
		?string $authMode = NULL
	): ResponseInterface {
		$dispatcher = simpleDispatcher(function (RouteCollector $r) use ($apiId, $version, $authMode) {
			foreach ($this->endpointDiscoveryService->getAllEndpoints() as $endpoint) {
				// Filter by API ID, version and auth mode if specified
				if (!empty($endpoint['apiId'])) {
					if (!in_array($apiId, $endpoint['apiId'], TRUE)) {
						continue;
					}
				}
				if (!empty($endpoint['version'])) {
					if (!in_array($version, $endpoint['version'], TRUE)) {
						continue;
					}
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

				$r->addRoute($endpoint['methods'], $endpoint['path'], [
					'controller' => $endpoint['controller'],
					'action' => $endpoint['action'],
					'authMode' => $endpoint['authMode'],
					'endpoint' => $endpoint
				]);
			}
		});

		$routeInfo = $dispatcher->dispatch($request->getMethod(), $path);

		switch ($routeInfo[0]) {
			case Dispatcher::NOT_FOUND:
				return $this->createErrorResponse('Not Found', 'The requested route does not exist.', 404);
			case Dispatcher::METHOD_NOT_ALLOWED:
				return $this->createErrorResponse(
					'Method Not Allowed',
					'The requested method is not allowed for this route.',
					405
				);
			case Dispatcher::FOUND:
				$handler = $routeInfo[1];
				/** @noinspection MultiAssignmentUsageInspection */
				$vars = $routeInfo[2];

				// 1. Validation Enforcement
				if (isset($handler['endpoint'])) {
					$validationErrors = $this->requestValidator->validate($request, $handler['endpoint'], $vars);
					if ($validationErrors !== NULL) {
						return $this->createErrorResponse(
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
					return $this->createErrorResponse('Unauthorized', 'Authentication required.', 401);
				}

				// 3. Scope Enforcement
				$scopeAttributes = $reflectionMethod->getAttributes(RequireScopes::class);
				if (count($scopeAttributes) > 0) {
					/** @var RequireScopes $requireScopes */
					$requireScopes = $scopeAttributes[0]->newInstance();
					foreach ($requireScopes->scopes as $scope) {
						if ($authContext === NULL || !$authContext->hasScope($scope)) {
							return $this->createErrorResponse(
								'Forbidden',
								'You do not have the required scope: ' . $scope,
								403
							);
						}
					}
				}

				// User Enforcement
				$userAttributes = $reflectionMethod->getAttributes(RequireUser::class);
				if (count($userAttributes) > 0) {
					if ($authContext === NULL || $authContext->getUserId() === NULL) {
						return $this->createErrorResponse(
							'Forbidden',
							'This endpoint requires a user login context.',
							403
						);
					}
				}

				$controller = $this->getControllerInstances()[$handler['controller']];
				return call_user_func_array([$controller, $handler['action']], [$request, ...array_values($vars)]);
		}

		return $this->createErrorResponse('Internal Server Error', 'An unexpected error occurred.', 500);
	}

	/**
	 * Creates a Problem JSON error response (RFC 7807)
	 *
	 * @param string $title
	 * @param string $detail
	 * @param int $status
	 * @param array $additionalData
	 * @return ResponseInterface
	 */
	protected function createErrorResponse(
		string $title,
		string $detail,
		int $status,
		array $additionalData = []
	): ResponseInterface {
		$response = [
			'title' => $title,
			'detail' => $detail,
			'status' => $status,
			'type' => 'about:blank'
		];

		if (!empty($additionalData)) {
			$response = array_merge($response, $additionalData);
		}

		return new JsonResponse($response, $status, ['Content-Type' => 'application/problem+json']);
	}
}
