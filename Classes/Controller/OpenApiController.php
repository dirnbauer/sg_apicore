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
use SGalinski\SgApiCore\Attribute\ApiRoute;
use SGalinski\SgApiCore\Service\OpenApiService;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Utility\PathUtility;

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

		$path = rtrim($request->getUri()->getPath(), '/');
		$baseUrl = (string) $request->getUri()->withPath(str_replace('/docs.json', '', $path));

		$tenantContext = $request->getAttribute('api.tenant');
		$tenantId = $tenantContext?->getTenantId() ?? '';

		$spec = $this->openApiService->generateSpec($apiId, $version, $baseUrl, $tenantId);
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

		$swaggerUiPath = 'EXT:sg_apicore/Resources/Public/Vendor/swagger-ui/';
		$cssPath = PathUtility::getPublicResourceWebPath($swaggerUiPath . 'swagger-ui.css');
		$bundleJsPath = PathUtility::getPublicResourceWebPath($swaggerUiPath . 'swagger-ui-bundle.js');
		$presetJsPath = PathUtility::getPublicResourceWebPath($swaggerUiPath . 'swagger-ui-standalone-preset.js');
		$fav32Path = PathUtility::getPublicResourceWebPath($swaggerUiPath . 'favicon-32x32.png');
		$fav16Path = PathUtility::getPublicResourceWebPath($swaggerUiPath . 'favicon-16x16.png');
		$logoPath = PathUtility::getPublicResourceWebPath('EXT:sg_apicore/Resources/Public/Images/sgalinski-logo.svg');
		$tokensCssPath = PathUtility::getPublicResourceWebPath('EXT:sg_apicore/Resources/Public/Stylesheet/tokens.css');
		$ciCssPath = PathUtility::getPublicResourceWebPath('EXT:sg_apicore/Resources/Public/Stylesheet/swagger-ci.css');
		$poweredByCssPath = PathUtility::getPublicResourceWebPath(
			'EXT:sg_apicore/Resources/Public/Stylesheet/powered-by.css'
		);
		$poweredByJsPath = PathUtility::getPublicResourceWebPath(
			'EXT:sg_apicore/Resources/Public/JavaScript/powered-by.js'
		);

		// Build the path to the docs.json relative to the current URL
		$path = rtrim($request->getUri()->getPath(), '/');
		$baseUrl = (string) $request->getUri()->withPath(str_replace('/docs/ui', '', $path));
		$docsUrl = (string) $request->getUri()->withPath(str_replace('/docs/ui', '/docs.json', $path));

		$debugInfo = '';
		$debugFlag = $request->getQueryParams()['debug'] ?? '';
		$debugFlag = strtolower((string) $debugFlag);
		if (in_array($debugFlag, ['1', 'true', 'yes'], TRUE)) {
			$tenantContext = $request->getAttribute('api.tenant');
			$tenantId = $tenantContext?->getTenantId() ?? '';
			$cacheInfo = $this->openApiService->getCacheDebugInfo($apiId, $version, $baseUrl, $tenantId);
			$cacheKey = htmlspecialchars($cacheInfo['cacheKey'] ?? '', ENT_QUOTES);
			$signature = htmlspecialchars($cacheInfo['signature'] ?? '', ENT_QUOTES);
			$debugInfo = '<div class="swagger-debug">Cache key: ' . $cacheKey
				. ' | Signature: ' . $signature . '</div>';
		}

		$html = <<<HTML
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
	<meta charset="UTF-8">
	<title>Swagger UI - {$apiId} (v{$version})</title>
	<link rel="stylesheet" type="text/css" href="{$cssPath}" >
	<link rel="stylesheet" type="text/css" href="{$tokensCssPath}" >
	<link rel="stylesheet" type="text/css" href="{$ciCssPath}" >
	<link rel="stylesheet" type="text/css" href="{$poweredByCssPath}" >
	<link rel="icon" type="image/png" href="{$fav32Path}" sizes="32x32" />
	<link rel="icon" type="image/png" href="{$fav16Path}" sizes="16x16" />
	<style>
		html { box-sizing: border-box; overflow-y: scroll; }
		*, *:before, *:after { box-sizing: inherit; }
		body { margin:0; background: #fafafa; }
		.swagger-debug { position: fixed; right: 8px; bottom: 8px; padding: 4px 6px; font-size: 11px; color: #111; background: #fff; border: 1px solid #ccc; opacity: 0.75; z-index: 9999; }
	</style>
</head>
<body>
	<div id="swagger-ui"></div>
	<div class="theme-switcher">
		<button onclick="setTheme('light')" class="btn-light" title="Light Theme">Light</button>
		<button onclick="setTheme('dark')" class="btn-dark" title="Dark Theme">Dark</button>
		<button onclick="setTheme('contrast')" class="btn-contrast" title="Contrast Theme">Contrast</button>
	</div>
	<script src="{$bundleJsPath}"> </script>
	<script src="{$presetJsPath}"> </script>
	<script>
		window.sgApiCoreLogoPath = '{$logoPath}';
		function setTheme(theme) {
			document.documentElement.setAttribute('data-theme', theme);
			localStorage.setItem('sg-apicore-theme', theme);
			document.querySelectorAll('.theme-switcher button').forEach(btn => {
				btn.classList.remove('active');
			});
			document.querySelector('.btn-' + theme).classList.add('active');
		}
		const savedTheme = localStorage.getItem('sg-apicore-theme') || 'dark';
		setTheme(savedTheme);
	</script>
	<script src="{$poweredByJsPath}"> </script>
	<script>
	window.onload = function() {
		const ui = SwaggerUIBundle({
			url: '{$docsUrl}',
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
	{$debugInfo}
</body>
</html>
HTML;

		return new HtmlResponse($html);
	}
}
