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
use SGalinski\SgApiCore\Controller\HealthController;
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
	protected array $controllers = [
		HealthController::class
	];

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
	 * @return ResponseInterface
	 * @throws \ReflectionException
	 */
	public function dispatch(
		ServerRequestInterface $request,
		string $apiId,
		string $version,
		string $path
	): ResponseInterface {
		$dispatcher = simpleDispatcher(function (RouteCollector $r) use ($apiId, $version) {
			foreach ($this->controllers as $controllerClass) {
				$reflectionClass = new \ReflectionClass($controllerClass);
				foreach ($reflectionClass->getMethods() as $method) {
					$attributes = $method->getAttributes(ApiRoute::class);
					foreach ($attributes as $attribute) {
						/** @var ApiRoute $routeAttribute */
						$routeAttribute = $attribute->newInstance();

						// Filter by API ID and version if specified
						if ($routeAttribute->apiId !== NULL && $routeAttribute->apiId !== $apiId) {
							continue;
						}
						if ($routeAttribute->version !== NULL && $routeAttribute->version !== $version) {
							continue;
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
