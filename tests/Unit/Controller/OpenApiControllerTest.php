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

namespace SGalinski\SgApiCore\Tests\Unit\Controller;

use SGalinski\SgApiCore\Configuration\ExtensionConfiguration;
use SGalinski\SgApiCore\Controller\OpenApiController;
use SGalinski\SgApiCore\Service\ApiRegistry;
use SGalinski\SgApiCore\Service\EndpointDiscoveryService;
use SGalinski\SgApiCore\Service\OpenApiService;
use SGalinski\SgApiCore\Service\SchemaRegistry;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Test case for OpenApiController
 */
class OpenApiControllerTest extends UnitTestCase {
	public function testJsonActionUsesCachedSpec(): void {
		$cachedSpec = [
			'openapi' => '3.0.3',
			'info' => [
				'title' => 'API: public (v1)',
				'version' => '1',
				'description' => 'Cached spec'
			],
			'paths' => [
				'/test' => [
					'get' => ['summary' => 'Cached']
				]
			]
		];

		$cache = $this->createMock(FrontendInterface::class);
		$cache->method('get')->willReturn($cachedSpec);
		$cacheManager = $this->createMock(CacheManager::class);
		$cacheManager->method('getCache')->with('sg_apicore_discovery')->willReturn($cache);

		$endpointDiscovery = $this->createMock(EndpointDiscoveryService::class);
		$endpointDiscovery->method('getDiscoverySignature')->willReturn('signature');
		$endpointDiscovery->expects($this->never())->method('getAllEndpoints');

		$apiRegistry = $this->createStub(ApiRegistry::class);
		$apiRegistry->method('getSecurityConfig')->willReturn(['authMode' => 'public']);

		$extensionConfiguration = $this->createStub(ExtensionConfiguration::class);
		$schemaRegistry = $this->createStub(SchemaRegistry::class);
		$schemaRegistry->method('getSchemas')->willReturn([]);
		$openApiService = new OpenApiService(
			$endpointDiscovery,
			$apiRegistry,
			$schemaRegistry,
			$extensionConfiguration,
			$cacheManager
		);

		$controller = new OpenApiController($openApiService);
		$request = new ServerRequest('https://example.com/api/public/v1/docs.json', 'GET');
		$request = $request->withAttribute('api.id', 'public')->withAttribute('api.version', '1');
		$request = $request->withUri(new Uri('https://example.com/api/public/v1/docs.json'));

		$response = $controller->jsonAction($request);
		$payload = json_decode((string) $response->getBody(), TRUE);

		$this->assertSame($cachedSpec, $payload);
	}
}
