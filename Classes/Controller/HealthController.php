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
use SGalinski\SgApiCore\Attribute\ApiRoute;
use SGalinski\SgApiCore\Attribute\RequireScopes;
use SGalinski\SgApiCore\Context\TenantContext;
use SGalinski\SgApiCore\Security\AuthContext;
use TYPO3\CMS\Core\Http\JsonResponse;

/**
 * Controller for health check and test endpoints
 */
class HealthController {
	/**
	 * Returns a simple health status (Global)
	 *
	 * @param ServerRequestInterface $request
	 * @return ResponseInterface
	 */
	#[ApiRoute(path: '/health', methods: ['GET'])]
	public function health(ServerRequestInterface $request): ResponseInterface {
		return new JsonResponse(['status' => 'ok']);
	}

	/**
	 * Test endpoint for 'public' API
	 *
	 * @param ServerRequestInterface $request
	 * @return ResponseInterface
	 */
	#[ApiRoute(path: '/test', methods: ['GET'], apiId: 'public', version: '1')]
	public function publicTest(ServerRequestInterface $request): ResponseInterface {
		return new JsonResponse([
			'api' => 'public',
			'version' => '1',
			'message' => 'Public API test successful'
		]);
	}

	/**
	 * Test endpoint for 'partner' API with required scope
	 *
	 * @param ServerRequestInterface $request
	 * @return ResponseInterface
	 */
	#[ApiRoute(path: '/test', methods: ['GET'], apiId: 'partner', version: '1')]
	#[RequireScopes(['partner:read'])]
	public function partnerTest(ServerRequestInterface $request): ResponseInterface {
		/** @var AuthContext $authContext */
		$authContext = $request->getAttribute('api.auth');
		/** @var TenantContext $tenantContext */
		$tenantContext = $request->getAttribute('api.tenant');

		return new JsonResponse([
			'api' => 'partner',
			'version' => '1',
			'tenant' => $tenantContext->getTenantId(),
			'tokenUid' => $authContext->getTokenUid(),
			'scopes' => $authContext->getScopes(),
			'message' => 'Partner API test successful'
		]);
	}

	/**
	 * Test endpoint for multi-scope requirement
	 *
	 * @param ServerRequestInterface $request
	 * @return ResponseInterface
	 */
	#[ApiRoute(path: '/admin-test', methods: ['GET'], version: '1')]
	#[RequireScopes(['admin', 'super-admin'])]
	public function adminTest(ServerRequestInterface $request): ResponseInterface {
		return new JsonResponse(['message' => 'Admin test successful']);
	}
}
