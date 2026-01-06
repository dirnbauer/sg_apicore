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
	 * @param iterable $controllers
	 */
	public function __construct(iterable $controllers) {
		$this->controllers = $controllers;
	}

	/**
	 * Returns all discovered endpoints
	 *
	 * @return array
	 * @throws \ReflectionException
	 */
	public function getAllEndpoints(): array {
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

					$endpoints[] = [
						'apiId' => is_array($route->apiId) ? $route->apiId : ($route->apiId !== NULL ? [$route->apiId] : []),
						'version' => is_array($route->version) ? $route->version : ($route->version !== NULL ? [$route->version] : []),
						'path' => $route->path,
						'methods' => $route->methods,
						'authMode' => is_array($route->authMode) ? $route->authMode : ($route->authMode !== NULL ? [$route->authMode] : []),
						'summary' => $endpoint?->summary ?? $method->getName(),
						'description' => $endpoint?->description ?? '',
						'tags' => $endpoint?->tags ?? [],
						'scopes' => $requireScopes?->scopes ?? [],
						'bodyParams' => $bodyParams,
						'queryParams' => $queryParams,
						'pathParams' => $pathParams,
						'responses' => $responses,
						'controller' => $controllerClass,
						'action' => $method->getName()
					];
				}
			}
		}

		return $endpoints;
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
