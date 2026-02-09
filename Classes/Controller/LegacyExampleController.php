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
	 * Example error action to show a legacy error format
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
