<?php

/***************************************************************
 *  Copyright notice
 *  (c) sgalinski Internet Services (https://www.sgalinski.de)
 *
 *  All rights reserved
 *
 *  This file is part of the TYPO3 CMS project.
 *  It is free software; you can redistribute it and/or modify it under
 *  the terms of the GNU General Public License, either version 3
 *  of the License, or any later version.
 ***************************************************************/

namespace SGalinski\SgApiCore\Service;

use SGalinski\SgApiCore\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Provides data for the backend rate limit dashboard
 */
class RateLimitDashboardService {
	protected const string TABLE_NAME = 'tx_apicore_rate_limit';

	public function __construct(
		protected readonly ExtensionConfiguration $extensionConfiguration,
		protected readonly ApiRegistry $apiRegistry,
		protected readonly ResourceRegistry $resourceRegistry,
		protected readonly ConnectionPool $connectionPool
	) {
	}

	/**
	 * @return array<string, mixed>
	 */
	public function getDashboardData(): array {
		$config = [
			'enabled' => $this->extensionConfiguration->isRateLimitEnabled(),
			'defaultLimit' => $this->extensionConfiguration->getRateLimitDefaultLimit(),
			'windowSeconds' => $this->extensionConfiguration->getRateLimitWindowSeconds(),
			'defaultBurst' => $this->extensionConfiguration->getRateLimitDefaultBurst(),
		];

		$defaultEffectiveLimit = $config['defaultLimit'] + $config['defaultBurst'];
		$apiOverrides = $this->buildApiOverrides($config);
		$resourceOverrides = $this->buildResourceOverrides($config);
		$counters = $this->buildCounters($apiOverrides, $defaultEffectiveLimit);
		$topClients = $this->buildTopClients($counters);
		$windowSummary = $this->buildWindowSummary($counters);

		return [
			'config' => $config,
			'apiOverrides' => $apiOverrides,
			'resourceOverrides' => $resourceOverrides,
			'counters' => $counters,
			'topClients' => $topClients,
			'windowSummary' => $windowSummary,
			'defaultEffectiveLimit' => $defaultEffectiveLimit,
		];
	}

	/**
	 * @param array<string, mixed> $config
	 * @return array<int, array<string, mixed>>
	 */
	protected function buildApiOverrides(array $config): array {
		$apiOverrides = [];
		foreach ($this->apiRegistry->getApis() as $apiId => $apiConfig) {
			$rateLimit = $apiConfig['rateLimit'] ?? $apiConfig['security']['rateLimit'] ?? NULL;
			$normalized = $this->normalizeRateLimitConfig(is_array($rateLimit) ? $rateLimit : NULL, $config);
			$versionOverrides = $this->buildVersionOverrides($rateLimit, $config);
			$hasOverride = is_array($rateLimit);

			$apiOverrides[] = [
				'apiId' => $apiId,
				'versions' => $apiConfig['versions'] ?? [],
				'hasOverride' => $hasOverride,
				'rateLimit' => $rateLimit,
				'normalized' => $normalized,
				'versionOverrides' => $versionOverrides,
			];
		}

		return $apiOverrides;
	}

	/**
	 * @param array<string, mixed> $config
	 * @return array<int, array<string, mixed>>
	 */
	protected function buildResourceOverrides(array $config): array {
		$resourceOverrides = [];
		foreach ($this->resourceRegistry->getResources() as $apiId => $resources) {
			foreach ($resources as $resource) {
				$rateLimit = $resource['rateLimit'] ?? NULL;
				if (!is_array($rateLimit) || $rateLimit === []) {
					continue;
				}

				$resourceOverrides[] = [
					'apiId' => $apiId,
					'table' => $resource['table'] ?? '',
					'basePath' => $resource['basePath'] ?? '',
					'rateLimit' => $rateLimit,
					'normalized' => $this->normalizeRateLimitConfig($rateLimit, $config),
				];
			}
		}

		return $resourceOverrides;
	}

	/**
	 * @param array<int, array<string, mixed>> $apiOverrides
	 * @param int $defaultEffectiveLimit
	 * @return array<int, array<string, mixed>>
	 */
	protected function buildCounters(array $apiOverrides, int $defaultEffectiveLimit): array {
		$apiLimits = [];
		foreach ($apiOverrides as $override) {
			$normalized = $override['normalized'] ?? [];
			if (!empty($override['apiId']) && is_array($normalized)) {
				$apiLimits[$override['apiId']] = $normalized;
			}
		}

		$queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE_NAME);
		$rows = $queryBuilder
			->select('identifier', 'hits', 'window_start', 'expires_at')
			->from(self::TABLE_NAME)
			->orderBy('hits', 'DESC')
			->executeQuery()
			->fetchAllAssociative();

		$now = time();
		$counters = [];
		foreach ($rows as $row) {
			$identifier = (string) $row['identifier'];
			$parsed = $this->parseIdentifier($identifier);
			$apiId = $parsed['apiId'];
			$limitConfig = $apiLimits[$apiId] ?? NULL;
			$effectiveLimit = $limitConfig['effectiveLimit'] ?? $defaultEffectiveLimit;

			$hits = (int) $row['hits'];
			$expiresAt = (int) $row['expires_at'];
			$remaining = max(0, $effectiveLimit - $hits);

			$counters[] = [
				'identifier' => $identifier,
				'apiId' => $apiId,
				'tenantId' => $parsed['tenantId'],
				'subject' => $parsed['subject'],
				'subjectType' => $parsed['subjectType'],
				'hits' => $hits,
				'windowStart' => (int) $row['window_start'],
				'expiresAt' => $expiresAt,
				'remaining' => $remaining,
				'effectiveLimit' => $effectiveLimit,
				'limitSource' => $limitConfig ? 'api' : 'global',
				'isExpired' => $expiresAt > 0 ? $expiresAt < $now : FALSE,
			];
		}

		return $counters;
	}

	/**
	 * @param array<int, array<string, mixed>> $counters
	 * @return array<int, array<string, mixed>>
	 */
	protected function buildTopClients(array $counters): array {
		$activeCounters = array_filter($counters, static fn (array $counter) => !$counter['isExpired']);
		usort($activeCounters, static fn (array $a, array $b) => $b['hits'] <=> $a['hits']);
		return array_slice($activeCounters, 0, 10);
	}

	/**
	 * @param array<int, array<string, mixed>> $counters
	 * @return array<int, array<string, mixed>>
	 */
	protected function buildWindowSummary(array $counters): array {
		$summary = [];
		foreach ($counters as $counter) {
			$windowStart = (int) $counter['windowStart'];
			if (!isset($summary[$windowStart])) {
				$summary[$windowStart] = [
					'windowStart' => $windowStart,
					'expiresAt' => (int) $counter['expiresAt'],
					'entries' => 0,
					'activeEntries' => 0,
					'totalHits' => 0,
				];
			}

			$summary[$windowStart]['entries']++;
			$summary[$windowStart]['totalHits'] += (int) $counter['hits'];
			if (!$counter['isExpired']) {
				$summary[$windowStart]['activeEntries']++;
			}
			$summary[$windowStart]['expiresAt'] = max(
				(int) $summary[$windowStart]['expiresAt'],
				(int) $counter['expiresAt']
			);
		}

		krsort($summary);
		return array_values($summary);
	}

	/**
	 * @param array<string, mixed>|null $rateLimit
	 * @param array<string, mixed> $config
	 * @return array<string, mixed>
	 */
	protected function normalizeRateLimitConfig(?array $rateLimit, array $config): array {
		$enabled = $rateLimit['enabled'] ?? $config['enabled'];
		$limit = (int) ($rateLimit['limit'] ?? $config['defaultLimit']);
		$windowSeconds = (int) ($rateLimit['windowSeconds'] ?? $config['windowSeconds']);
		$burst = (int) ($rateLimit['burst'] ?? $config['defaultBurst']);

		return [
			'enabled' => (bool) $enabled,
			'limit' => $limit,
			'windowSeconds' => $windowSeconds,
			'burst' => $burst,
			'effectiveLimit' => $limit + $burst,
		];
	}

	/**
	 * @param mixed $rateLimit
	 * @param array<string, mixed> $config
	 * @return array<int, array<string, mixed>>
	 */
	protected function buildVersionOverrides(mixed $rateLimit, array $config): array {
		if (!is_array($rateLimit) || !isset($rateLimit['versions']) || !is_array($rateLimit['versions'])) {
			return [];
		}

		$overrides = [];
		foreach ($rateLimit['versions'] as $version => $versionConfig) {
			if (!is_array($versionConfig)) {
				continue;
			}

			$overrides[] = [
				'version' => (string) $version,
				'normalized' => $this->normalizeRateLimitConfig($versionConfig, $config),
			];
		}

		return $overrides;
	}

	/**
	 * @param string $identifier
	 * @return array{apiId: string, tenantId: string, subject: string, subjectType: string}
	 */
	protected function parseIdentifier(string $identifier): array {
		$apiId = 'unknown';
		$tenantId = 'unknown';
		$subject = $identifier;
		$subjectType = 'unknown';

		$parts = explode(':', $identifier, 3);
		if (count($parts) === 3) {
			$apiId = $parts[0];
			$tenantId = $parts[1];
			$subject = $parts[2];
		}

		if (str_starts_with($subject, 'token:')) {
			$subjectType = 'token';
		} elseif (str_starts_with($subject, 'user:')) {
			$subjectType = 'user';
		} elseif (str_starts_with($subject, 'ip:')) {
			$subjectType = 'ip';
		}

		return [
			'apiId' => $apiId,
			'tenantId' => $tenantId,
			'subject' => $subject,
			'subjectType' => $subjectType,
		];
	}
}
