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

use SGalinski\SgApiCore\Attribute\ApiMcp;
use SGalinski\SgApiCore\Service\ApiRegistry;
use SGalinski\SgApiCore\Service\BackendMcpOverviewService;
use SGalinski\SgApiCore\Service\McpToolService;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

class BackendMcpOverviewServiceTest extends UnitTestCase {
	public function testEnrichEndpointsWithMcpInfoAddsExposedToolsForExplicitApiAndVersion(): void {
		$apiRegistry = new ApiRegistry();
		$apiRegistry->registerApi('public', ['1'], ['authMode' => 'public']);

		$mcpToolService = $this->createMock(McpToolService::class);
		$mcpToolService->expects($this->once())->method('getAuthModeForApi')->with('public', '1')->willReturn('public');
		$mcpToolService->expects($this->once())->method('listResolvedTools')->with('public', '1', 'public')->willReturn([
			[
				'endpointId' => 'public:1:get:/articles',
				'apiId' => 'public',
				'version' => '1',
				'httpMethod' => 'GET',
				'path' => '/articles',
				'tool' => ['name' => 'public_get_articles'],
			],
		]);

		$service = new BackendMcpOverviewService($mcpToolService, $apiRegistry);
		$endpoints = [
			[
				'apiId' => ['public'],
				'version' => ['1'],
				'methods' => ['GET'],
				'path' => '/articles',
				'mcp' => NULL,
			],
		];

		$enrichedEndpoints = $service->enrichEndpointsWithMcpInfo($endpoints);

		$this->assertTrue($enrichedEndpoints[0]['mcpInfo']['isExposed']);
		$this->assertSame(1, $enrichedEndpoints[0]['mcpInfo']['exposedToolsCount']);
		$this->assertSame('public_get_articles', $enrichedEndpoints[0]['mcpInfo']['exposedTools'][0]['toolName']);
	}

	public function testEnrichEndpointsWithMcpInfoResolvesGlobalEndpointsAcrossApis(): void {
		$apiRegistry = new ApiRegistry();
		$apiRegistry->registerApi('public', ['1'], ['authMode' => 'public']);
		$apiRegistry->registerApi('partner', ['2'], ['authMode' => 'token']);

		$mcpToolService = $this->createStub(McpToolService::class);
		$mcpToolService->method('getAuthModeForApi')->willReturnMap([
			['public', '1', 'public'],
			['partner', '2', 'token'],
		]);
		$mcpToolService->method('listResolvedTools')->willReturnCallback(
			static function (string $apiId, string $version, string $authMode): array {
				if ($apiId === 'public' && $version === '1' && $authMode === 'public') {
					return [[
						'endpointId' => 'public:1:get:/health',
						'apiId' => 'public',
						'version' => '1',
						'httpMethod' => 'GET',
						'path' => '/health',
						'tool' => ['name' => 'public_get_health'],
					]];
				}
				if ($apiId === 'partner' && $version === '2' && $authMode === 'token') {
					return [[
						'endpointId' => 'partner:2:get:/health',
						'apiId' => 'partner',
						'version' => '2',
						'httpMethod' => 'GET',
						'path' => '/health',
						'tool' => ['name' => 'partner_get_health'],
					]];
				}
				return [];
			}
		);

		$service = new BackendMcpOverviewService($mcpToolService, $apiRegistry);
		$endpoints = [
			[
				'apiId' => [],
				'version' => [],
				'methods' => ['GET'],
				'path' => '/health',
				'mcp' => NULL,
			],
		];

		$enrichedEndpoints = $service->enrichEndpointsWithMcpInfo($endpoints);
		$toolNames = array_values(array_unique(array_column($enrichedEndpoints[0]['mcpInfo']['exposedTools'], 'toolName')));
		sort($toolNames);

		$this->assertTrue($enrichedEndpoints[0]['mcpInfo']['isExposed']);
		$this->assertSame(2, $enrichedEndpoints[0]['mcpInfo']['exposedToolsCount']);
		$this->assertSame(['partner_get_health', 'public_get_health'], $toolNames);
	}

	public function testEnrichEndpointsWithMcpInfoContainsAttributeOverridesAndExcludeState(): void {
		$apiRegistry = new ApiRegistry();
		$apiRegistry->registerApi('public', ['1'], ['authMode' => 'public']);

		$mcpToolService = $this->createMock(McpToolService::class);
		$mcpToolService->expects($this->once())->method('getAuthModeForApi')->with('public', '1')->willReturn('public');
		$mcpToolService->expects($this->once())->method('listResolvedTools')->with('public', '1', 'public')->willReturn([]);

		$service = new BackendMcpOverviewService($mcpToolService, $apiRegistry);
		$endpoints = [
			[
				'apiId' => ['public'],
				'version' => ['1'],
				'methods' => ['GET'],
				'path' => '/internal/report',
				'mcp' => new ApiMcp(exclude: TRUE, name: 'public_get_internal_report'),
			],
		];

		$enrichedEndpoints = $service->enrichEndpointsWithMcpInfo($endpoints);

		$this->assertTrue($enrichedEndpoints[0]['mcpInfo']['excludedByAttribute']);
		$this->assertSame('public_get_internal_report', $enrichedEndpoints[0]['mcpInfo']['nameOverride']);
		$this->assertFalse($enrichedEndpoints[0]['mcpInfo']['isExposed']);
	}
}
