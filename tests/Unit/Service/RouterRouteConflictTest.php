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

namespace SGalinski\SgApiCore\Tests\Unit\Service;

use PHPUnit\Framework\MockObject\MockObject;
use SGalinski\SgApiCore\Service\EndpointDiscoveryService;
use SGalinski\SgApiCore\Service\RequestValidator;
use SGalinski\SgApiCore\Service\ResponseService;
use SGalinski\SgApiCore\Service\Router;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

class DummyController {
	public function getAction() {
		return new Response();
	}

	public function listAction() {
		return new Response();
	}
}

class RouterRouteConflictTest extends UnitTestCase {
	protected Router $router;
	protected MockObject|EndpointDiscoveryService $endpointDiscoveryService;

	protected function setUp(): void {
		parent::setUp();
		$this->endpointDiscoveryService = $this->createStub(EndpointDiscoveryService::class);
		$requestValidator = $this->createStub(RequestValidator::class);
		$responseService = $this->createStub(ResponseService::class);

		$this->router = new class([], $this->endpointDiscoveryService, $requestValidator, $responseService) extends Router {
			public function __construct($controllers, $endpointDiscoveryService, $requestValidator, $responseService) {
				parent::__construct(
					$controllers,
					$endpointDiscoveryService,
					$requestValidator,
					$responseService
				);
			}

			protected function getControllerInstances(): array {
				return [
					DummyController::class => new DummyController(),
				];
			}
		};
	}

	public function testRouteConflictIsResolvedBySorting(): void {
		// Define endpoints in an order that would normally cause a conflict in fast-route
		// (Variable route before static route that it shadows)
		$endpoints = [
			[
				'path' => '/news/news/{id}',
				'methods' => ['GET'],
				'controller' => DummyController::class,
				'action' => 'getAction',
				'apiId' => ['legacy'],
				'version' => [],
				'authMode' => ['public'],
			],
			[
				'path' => '/news/news/list',
				'methods' => ['GET'],
				'controller' => DummyController::class,
				'action' => 'listAction',
				'apiId' => ['legacy'],
				'version' => [],
				'authMode' => ['public'],
			],
		];

		$this->endpointDiscoveryService->method('getAllEndpoints')->willReturn($endpoints);

		$request = new ServerRequest('https://example.com/api/legacy/v1/news/news/list', 'GET');

		// If the conflict is not resolved, dispatch() will throw a RuntimeException or similar from fast-route
		$response = $this->router->dispatch($request, 'legacy', 'v1', '/news/news/list');
		$this->assertEquals(200, $response->getStatusCode(), 'The request should succeed.');
	}
}
