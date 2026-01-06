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
use SGalinski\SgApiCore\Security\AuthContext;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use function FastRoute\simpleDispatcher;

/**
 * Router service for the API
 */
class Router implements SingletonInterface {
	/**
	 * @var array
	 */
	protected array $controllers = [];

	/**
	 * @param \Traversable $controllers
	 */
	public function __construct(\Traversable $controllers) {
		foreach ($controllers as $controller) {
			$this->controllers[] = get_class($controller);
		}
	}

	/**
	 * @param array $controllers
	 */
	public function setControllers(array $controllers): void {
		$this->controllers = $controllers;
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
			foreach ($this->controllers as $controllerClass) {
				$reflectionClass = new \ReflectionClass($controllerClass);
				foreach ($reflectionClass->getMethods() as $method) {
					$attributes = $method->getAttributes(ApiRoute::class);
					foreach ($attributes as $attribute) {
						/** @var ApiRoute $routeAttribute */
						$routeAttribute = $attribute->newInstance();

						// Filter by API ID, version and auth mode if specified
						if ($routeAttribute->apiId !== NULL) {
							$apiIds = is_array($routeAttribute->apiId) ? $routeAttribute->apiId : [$routeAttribute->apiId];
							if (!in_array($apiId, $apiIds, TRUE)) {
								continue;
							}
						}
						if ($routeAttribute->version !== NULL) {
							$versions = is_array($routeAttribute->version) ? $routeAttribute->version : [$routeAttribute->version];
							if (!in_array($version, $versions, TRUE)) {
								continue;
							}
						}
						if ($routeAttribute->authMode !== NULL) {
							$authModes = is_array($routeAttribute->authMode) ? $routeAttribute->authMode : [$routeAttribute->authMode];
							if (!in_array($authMode, $authModes, TRUE)) {
								continue;
							}
						}

						$r->addRoute($routeAttribute->methods, $routeAttribute->path, [
							'controller' => $controllerClass,
							'action' => $method->getName()
						]);
					}
				}
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

				// Scope Enforcement
				$reflectionClass = new \ReflectionClass($handler['controller']);
				$reflectionMethod = $reflectionClass->getMethod($handler['action']);
				$scopeAttributes = $reflectionMethod->getAttributes(RequireScopes::class);
				if (count($scopeAttributes) > 0) {
					/** @var AuthContext|null $authContext */
					$authContext = $request->getAttribute('api.auth');
					if ($authContext === NULL) {
						return $this->createErrorResponse('Unauthorized', 'Authentication required.', 401);
					}

					/** @var RequireScopes $requireScopes */
					$requireScopes = $scopeAttributes[0]->newInstance();
					foreach ($requireScopes->scopes as $scope) {
						if (!$authContext->hasScope($scope)) {
							return $this->createErrorResponse(
								'Forbidden',
								'You do not have the required scope: ' . $scope,
								403
							);
						}
					}
				}

				$controller = GeneralUtility::makeInstance($handler['controller']);
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
	 * @return ResponseInterface
	 */
	protected function createErrorResponse(string $title, string $detail, int $status): ResponseInterface {
		return new JsonResponse([
			'title' => $title,
			'detail' => $detail,
			'status' => $status,
			'type' => 'about:blank'
		], $status, ['Content-Type' => 'application/problem+json']);
	}
}
