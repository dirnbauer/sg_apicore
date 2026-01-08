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
use SGalinski\SgApiCore\Attribute\ApiRoute;
use SGalinski\SgApiCore\Service\OpenApiService;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Http\JsonResponse;

/**
 * Controller to serve OpenAPI documentation
 */
class OpenApiController {
	/**
	 * @var OpenApiService
	 */
	protected OpenApiService $openApiService;

	/**
	 * @param OpenApiService $openApiService
	 */
	public function __construct(OpenApiService $openApiService) {
		$this->openApiService = $openApiService;
	}

	/**
	 * Serves the OpenAPI specification as JSON
	 *
	 * @param ServerRequestInterface $request
	 * @return ResponseInterface
	 * @throws \ReflectionException
	 */
	#[ApiRoute(path: '/docs.json', methods: ['GET'], authMode: 'public')]
	#[ApiEndpoint(summary: 'openAPI Docs', tags: ['OpenAPI'])]
	public function jsonAction(ServerRequestInterface $request): ResponseInterface {
		$apiId = (string) $request->getAttribute('api.id');
		$version = (string) ($request->getAttribute('api.version') ?? '1');

		$spec = $this->openApiService->generateSpec($apiId, $version);
		return new JsonResponse($spec);
	}

	/**
	 * Serves the Swagger UI
	 *
	 * @param ServerRequestInterface $request
	 * @return ResponseInterface
	 */
	#[ApiRoute(path: '/docs/ui', methods: ['GET'], authMode: 'public')]
	#[ApiEndpoint(summary: 'openAPI Docs UI', tags: ['OpenAPI'])]
	public function uiAction(ServerRequestInterface $request): ResponseInterface {
		$apiId = (string) $request->getAttribute('api.id');
		$version = (string) ($request->getAttribute('api.version') ?? '1');

		// Build the path to the docs.json relative to the current URL
		// We use a relative path that works regardless of whether the URL has a trailing slash or not.
		// If the URL is /api/public/v1/docs/ui -> relative to /api/public/v1/docs/ -> ../docs.json is /api/public/v1/docs.json
		// If the URL is /api/public/v1/docs/ui/ -> relative to /api/public/v1/docs/ui/ -> ../../docs.json is /api/public/v1/docs.json
		// We can use a script to determine the correct path in the browser.
		$html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<title>Swagger UI - {$apiId} (v{$version})</title>
	<link rel="stylesheet" type="text/css" href="https://unpkg.com/swagger-ui-dist@4/swagger-ui.css" >
	<style>
		html { box-sizing: border-box; overflow-y: scroll; }
		*, *:before, *:after { box-sizing: inherit; }
		body { margin:0; background: #fafafa; }
	</style>
</head>
<body>
	<div id="swagger-ui"></div>
	<script src="https://unpkg.com/swagger-ui-dist@4/swagger-ui-bundle.js"> </script>
	<script src="https://unpkg.com/swagger-ui-dist@4/swagger-ui-standalone-preset.js"> </script>
	<script>
	window.onload = function() {
		// Calculate the path to docs.json relative to docs/ui
		let pathParts = window.location.pathname.split('/');
		if (pathParts[pathParts.length - 1] === '') {
			pathParts.pop(); // remove trailing slash part
		}
		pathParts.pop(); // remove 'ui'
		pathParts.pop(); // remove 'docs'
		let docsUrl = pathParts.join('/') + '/docs.json';

		const ui = SwaggerUIBundle({
			url: docsUrl,
			dom_id: '#swagger-ui',
			deepLinking: true,
			presets: [
				SwaggerUIBundle.presets.apis,
				SwaggerUIStandalonePreset
			],
			plugins: [
				SwaggerUIBundle.plugins.DownloadUrl
			],
			layout: "StandaloneLayout"
		})
		window.ui = ui
	}
	</script>
</body>
</html>
HTML;

		return new HtmlResponse($html);
	}
}
