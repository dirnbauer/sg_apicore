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
use SGalinski\SgApiCore\Attribute\ApiLegacyMode;
use SGalinski\SgApiCore\Attribute\ApiRoute;
use SGalinski\SgApiCore\Service\ResponseService;

/**
 * Controller showcasing the legacy compatibility mode
 */
#[ApiLegacyMode(source: 'sg_rest', wrapData: TRUE, legacyErrorFormat: TRUE)]
class LegacyExampleController {
	/**
	 * @var ResponseService
	 */
	protected ResponseService $responseService;

	/**
	 * @param ResponseService $responseService
	 */
	public function __construct(ResponseService $responseService) {
		$this->responseService = $responseService;
	}

	/**
	 * Example list action
	 * URL (legacy): /my-api-key/example/list
	 * URL (new): {apiPathPrefix}/my-api-key/v1/example/list
	 *
	 * @param ServerRequestInterface $request
	 * @return ResponseInterface
	 */
	#[ApiRoute(path: '/example/list', methods: ['GET'])]
	#[ApiEndpoint(summary: 'Legacy Example List', tags: ['Legacy'])]
	public function listAction(ServerRequestInterface $request): ResponseInterface {
		$data = [
			['id' => 1, 'name' => 'Legacy Item 1'],
			['id' => 2, 'name' => 'Legacy Item 2']
		];

		$legacyMode = $request->getAttribute('api.legacyMode');
		return $this->responseService->createSuccessResponse($data, [], 200, $legacyMode);
	}

	/**
	 * Example error action to show legacy error format
	 *
	 * @param ServerRequestInterface $request
	 * @return ResponseInterface
	 */
	#[ApiRoute(path: '/example/error', methods: ['GET'])]
	#[ApiEndpoint(summary: 'Legacy Example Error', tags: ['Legacy'])]
	public function errorAction(ServerRequestInterface $request): ResponseInterface {
		$legacyMode = $request->getAttribute('api.legacyMode');
		return $this->responseService->createErrorResponse(
			'Legacy Error',
			'This is an error in the old format.',
			400,
			'about:blank',
			[],
			$legacyMode
		);
	}
}
