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

namespace SGalinski\SgApiCore\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SGalinski\SgApiCore\Attribute\ApiEndpoint;
use SGalinski\SgApiCore\Attribute\ApiPathParam;
use SGalinski\SgApiCore\Attribute\ApiQueryParam;
use SGalinski\SgApiCore\Attribute\ApiResponse;
use SGalinski\SgApiCore\Attribute\ApiRoute;
use SGalinski\SgApiCore\Attribute\RequireScopes;
use SGalinski\SgApiCore\Context\TenantContext;
use SGalinski\SgApiCore\Security\AuthContext;
use SGalinski\SgApiCore\Service\PaginationService;
use SGalinski\SgApiCore\Service\ResponseService;

/**
 * Test and Example controller for demonstration purposes
 */
class TestController {
	/**
	 * @var ResponseService
	 */
	protected ResponseService $responseService;

	/**
	 * @var PaginationService
	 */
	protected PaginationService $paginationService;

	/**
	 * @param ResponseService $responseService
	 * @param PaginationService $paginationService
	 */
	public function __construct(ResponseService $responseService, PaginationService $paginationService) {
		$this->responseService = $responseService;
		$this->paginationService = $paginationService;
	}

	/**
	 * Returns a simple health status (Global)
	 *
	 * @param ServerRequestInterface $request
	 * @return ResponseInterface
	 */
	#[ApiRoute(path: '/health', methods: ['GET'])]
	#[ApiEndpoint(summary: 'Global health check', tags: ['Health'])]
	#[ApiResponse(status: 200, description: 'API is healthy')]
	public function health(ServerRequestInterface $request): ResponseInterface {
		return $this->responseService->createSuccessResponse(['status' => 'ok']);
	}

	/**
	 * Test endpoint for 'public' API
	 *
	 * @param ServerRequestInterface $request
	 * @return ResponseInterface
	 */
	#[ApiRoute(path: '/test', methods: ['GET'], apiId: 'public', version: '1')]
	#[ApiEndpoint(summary: 'Public API test', tags: ['Test'])]
	public function publicTest(ServerRequestInterface $request): ResponseInterface {
		return $this->responseService->createSuccessResponse([
			'api' => 'public',
			'version' => '1',
			'message' => 'Public API test successful'
		]);
	}

	/**
	 * Test endpoint for 'partner' API with the required scope
	 *
	 * @param ServerRequestInterface $request
	 * @return ResponseInterface
	 */
	#[ApiRoute(path: '/test', methods: ['GET'], apiId: 'partner', version: '1')]
	#[ApiEndpoint(summary: 'Partner API test', tags: ['Test'])]
	#[RequireScopes(['partner:read'])]
	public function partnerTest(ServerRequestInterface $request): ResponseInterface {
		/** @var AuthContext $authContext */
		$authContext = $request->getAttribute('api.auth');
		/** @var TenantContext $tenantContext */
		$tenantContext = $request->getAttribute('api.tenant');

		return $this->responseService->createSuccessResponse([
			'api' => 'partner',
			'version' => '1',
			'tenant' => $tenantContext->getTenantId(),
			'tokenUid' => $authContext->getTokenUid(),
			'scopes' => $authContext->getScopes(),
			'message' => 'Partner API test successful'
		]);
	}

	/**
	 * Test endpoint for 'user' level API (opaque access tokens)
	 *
	 * @param ServerRequestInterface $request
	 * @return ResponseInterface
	 */
	#[ApiRoute(path: '/user-test', methods: ['GET'], apiId: 'user', version: '1')]
	#[ApiEndpoint(summary: 'User-Level API test', tags: ['Test'])]
	public function userTest(ServerRequestInterface $request): ResponseInterface {
		/** @var AuthContext $authContext */
		$authContext = $request->getAttribute('api.auth');

		return $this->responseService->createSuccessResponse([
			'api' => 'user',
			'userId' => $authContext->getUserId(),
			'scopes' => $authContext->getScopes(),
			'message' => 'User-Level API test successful'
		]);
	}

	/**
	 * Test endpoint for multi-scope requirement
	 *
	 * @param ServerRequestInterface $request
	 * @return ResponseInterface
	 */
	#[ApiRoute(path: '/admin-test', methods: ['GET'], version: '1')]
	#[ApiEndpoint(summary: 'Admin test', tags: ['Test'])]
	#[RequireScopes(['admin', 'super-admin'])]
	public function adminTest(ServerRequestInterface $request): ResponseInterface {
		return $this->responseService->createSuccessResponse(['message' => 'Admin test successful']);
	}

	/**
	 * Returns a list of example items
	 *
	 * @param ServerRequestInterface $request
	 * @return ResponseInterface
	 */
	#[ApiRoute(path: '/examples', methods: ['GET'], apiId: 'public', version: '1')]
	#[ApiEndpoint(
		summary: 'Get a list of examples',
		description: 'This endpoint returns a list of example items for demonstration purposes.',
		tags: ['Examples']
	)]
	#[ApiQueryParam(name: 'offset', type: 'integer', description: 'The offset to start from')]
	#[ApiQueryParam(name: 'limit', type: 'integer', description: 'Maximum number of items to return')]
	#[ApiResponse(status: 200, description: 'Success', schema: 'ExampleItem[]')]
	public function listAction(ServerRequestInterface $request): ResponseInterface {
		$pagination = $this->paginationService->getPaginationParams($request);
		$offset = $pagination['offset'];
		$limit = $pagination['limit'];

		$data = [
			['id' => 1, 'name' => 'Example 1'],
			['id' => 2, 'name' => 'Example 2'],
			['id' => 3, 'name' => 'Example 3'],
			['id' => 4, 'name' => 'Example 4'],
			['id' => 5, 'name' => 'Example 5'],
		];

		$total = count($data);
		$slicedData = array_slice($data, $offset, $limit);

		return $this->responseService->createSuccessResponse(
			$slicedData,
			$this->paginationService->buildPaginationMeta($total, $offset, $limit)
		);
	}

	/**
	 * Returns a single example item by ID
	 *
	 * @param ServerRequestInterface $request
	 * @param string $id
	 * @return ResponseInterface
	 */
	#[ApiRoute(path: '/examples/{id}', methods: ['GET'], apiId: 'public', version: '1')]
	#[ApiEndpoint(
		summary: 'Get a single example',
		description: 'Returns a single example item by its unique ID.',
		tags: ['Examples']
	)]
	#[ApiPathParam(name: 'id', type: 'integer', description: 'The unique ID of the example')]
	#[ApiResponse(status: 200, description: 'Success', schema: 'ExampleItem')]
	#[ApiResponse(status: 404, description: 'Example not found')]
	public function getAction(ServerRequestInterface $request, string $id): ResponseInterface {
		if ($id === '1') {
			return $this->responseService->createSuccessResponse(['id' => 1, 'name' => 'Example 1']);
		}

		return $this->responseService->createErrorResponse('Not Found', 'The requested example was not found.', 404);
	}
}
