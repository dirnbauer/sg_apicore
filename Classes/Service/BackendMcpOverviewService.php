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

namespace SGalinski\SgApiCore\Service;

use ReflectionException;
use SGalinski\SgApiCore\Attribute\ApiMcp;
use TYPO3\CMS\Core\SingletonInterface;

/**
 * Builds MCP exposure information for backend endpoint overviews.
 */
class BackendMcpOverviewService implements SingletonInterface {
	/**
	 * @param McpToolService $mcpToolService
	 * @param ApiRegistry $apiRegistry
	 */
	public function __construct(
		protected McpToolService $mcpToolService,
		protected ApiRegistry $apiRegistry
	) {
	}

	/**
	 * Enrich endpoints with MCP exposure metadata.
	 *
	 * @param array $endpoints
	 * @return array
	 * @throws ReflectionException
	 */
	public function enrichEndpointsWithMcpInfo(array $endpoints): array {
		$apis = $this->apiRegistry->getApis();
		$resolvedToolIndex = $this->buildResolvedToolIndex($apis);

		foreach ($endpoints as &$endpoint) {
			$path = (string) ($endpoint['path'] ?? '/');
			$methods = array_values(array_unique(array_filter(array_map(
				static fn (mixed $method): string => strtoupper(trim((string) $method)),
				(array) ($endpoint['methods'] ?? [])
			), static fn (string $method): bool => $method !== '')));

			$endpointApiIds = array_values(array_filter(array_map(
				'strval',
				(array) ($endpoint['apiId'] ?? [])
			), static fn (string $apiId): bool => $apiId !== ''));

			$apiIds = $endpointApiIds !== [] ? $endpointApiIds : array_values(array_map('strval', array_keys($apis)));
			$matches = [];
			foreach ($apiIds as $apiId) {
				$apiConfig = $apis[$apiId] ?? NULL;
				if (!\is_array($apiConfig)) {
					continue;
				}

				$endpointVersions = array_values(array_filter(array_map(
					'strval',
					(array) ($endpoint['version'] ?? [])
				), static fn (string $version): bool => $version !== ''));

				$versions = $endpointVersions !== []
					? $endpointVersions
					: array_values(array_filter(array_map(
						'strval',
						(array) ($apiConfig['versions'] ?? [])
					), static fn (string $version): bool => $version !== ''));

				foreach ($versions as $version) {
					foreach ($methods as $method) {
						$key = strtolower($apiId . ':' . $version . ':' . $method . ':' . $path);
						if (isset($resolvedToolIndex[$key])) {
							$matches = array_merge($matches, $resolvedToolIndex[$key]);
						}
					}
				}
			}

			$mcpConfig = $endpoint['mcp'] ?? NULL;
			$isMcpConfig = $mcpConfig instanceof ApiMcp;

			$uniqueMatches = [];
			foreach ($matches as $match) {
				$uniqueKey = strtolower((string) ($match['endpointId'] ?? '') . '|' . (string) ($match['toolName'] ?? ''));
				$uniqueMatches[$uniqueKey] = $match;
			}
			$matches = array_values($uniqueMatches);

			$endpoint['mcpInfo'] = [
				'isConfigured' => $isMcpConfig,
				'excludedByAttribute' => $isMcpConfig ? (bool) $mcpConfig->exclude : FALSE,
				'nameOverride' => $isMcpConfig ? trim((string) ($mcpConfig->name ?? '')) : '',
				'descriptionOverride' => $isMcpConfig ? trim((string) ($mcpConfig->description ?? '')) : '',
				'notes' => $isMcpConfig ? trim((string) ($mcpConfig->notes ?? '')) : '',
				'isExposed' => $matches !== [],
				'exposedToolsCount' => \count($matches),
				'exposedTools' => $matches,
			];
		}
		unset($endpoint);

		return $endpoints;
	}

	/**
	 * @param array $apis
	 * @return array
	 * @throws ReflectionException
	 */
	protected function buildResolvedToolIndex(array $apis): array {
		$index = [];
		foreach ($apis as $apiId => $apiConfig) {
			if (!\is_array($apiConfig)) {
				continue;
			}

			$versions = array_values(array_filter(array_map(
				'strval',
				(array) ($apiConfig['versions'] ?? [])
			), static fn (string $version): bool => $version !== ''));

			foreach ($versions as $version) {
				$authMode = $this->mcpToolService->getAuthModeForApi((string) $apiId, $version);
				$resolvedTools = $this->mcpToolService->listResolvedTools((string) $apiId, $version, $authMode);

				foreach ($resolvedTools as $entry) {
					$method = strtoupper((string) ($entry['httpMethod'] ?? ''));
					$path = (string) ($entry['path'] ?? '/');
					$key = strtolower((string) $apiId . ':' . $version . ':' . $method . ':' . $path);
					$index[$key][] = [
						'apiId' => (string) $apiId,
						'version' => $version,
						'method' => $method,
						'path' => $path,
						'toolName' => (string) ($entry['tool']['name'] ?? ''),
						'endpointId' => (string) ($entry['endpointId'] ?? ''),
					];
				}
			}
		}

		return $index;
	}
}
