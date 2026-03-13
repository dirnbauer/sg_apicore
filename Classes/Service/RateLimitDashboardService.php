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
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Provides data for the backend rate limit dashboard
 */
class RateLimitDashboardService {
	protected const string TABLE_NAME = 'tx_apicore_rate_limit';
	protected const int HISTORY_WINDOW_SECONDS = 86400;
	protected const int DEFAULT_PER_PAGE = 50;
	protected const array ALLOWED_PER_PAGE = [25, 50, 100];

	public function __construct(
		protected readonly ExtensionConfiguration $extensionConfiguration,
		protected readonly ApiRegistry $apiRegistry,
		protected readonly ResourceRegistry $resourceRegistry,
		protected readonly ConnectionPool $connectionPool
	) {
	}

	/**
	 * @param array<string, mixed> $filters
	 * @return array<string, mixed>
	 */
	public function getDashboardData(array $filters = []): array {
		$config = [
			'enabled' => $this->extensionConfiguration->isRateLimitEnabled(),
			'defaultLimit' => $this->extensionConfiguration->getRateLimitDefaultLimit(),
			'windowSeconds' => $this->extensionConfiguration->getRateLimitWindowSeconds(),
			'defaultBurst' => $this->extensionConfiguration->getRateLimitDefaultBurst(),
		];

		$defaultEffectiveLimit = $config['defaultLimit'] + $config['defaultBurst'];
		$apiOverrides = $this->buildApiOverrides($config);
		$resourceOverrides = $this->buildResourceOverrides($config);
		$normalizedFilters = $this->normalizeFilters($filters);
		$allCounters = $this->buildCounters($apiOverrides, $defaultEffectiveLimit, $normalizedFilters);
		$paginationResult = $this->paginateCounters(
			$allCounters,
			$normalizedFilters['page'],
			$normalizedFilters['perPage']
		);
		$topClients = $this->buildTopClients($allCounters, $normalizedFilters['includeHistory']);
		$windowSummary = $this->buildWindowSummary($allCounters);

		return [
			'config' => $config,
			'apiOverrides' => $apiOverrides,
			'resourceOverrides' => $resourceOverrides,
			'counters' => $paginationResult['items'],
			'topClients' => $topClients,
			'windowSummary' => $windowSummary,
			'defaultEffectiveLimit' => $defaultEffectiveLimit,
			'filters' => $normalizedFilters,
			'filterOptions' => $this->buildFilterOptions($apiOverrides),
			'pagination' => $paginationResult['pagination'],
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
	 * @param array<string, mixed> $filters
	 * @return array<int, array<string, mixed>>
	 */
	protected function buildCounters(array $apiOverrides, int $defaultEffectiveLimit, array $filters = []): array {
		$normalizedFilters = $this->normalizeFilters($filters);
		$apiLimits = [];
		foreach ($apiOverrides as $override) {
			$normalized = $override['normalized'] ?? [];
			if (!empty($override['apiId']) && is_array($normalized)) {
				$apiLimits[$override['apiId']] = $normalized;
			}
		}

		$now = time();
		$minExpiresAt = $normalizedFilters['includeHistory'] ? $now - self::HISTORY_WINDOW_SECONDS : $now;
		$rows = $this->fetchRateLimitRows($minExpiresAt);
		$counters = [];
		foreach ($rows as $row) {
			$expiresAt = (int) $row['expires_at'];
			$isExpired = $expiresAt > 0 && $expiresAt < $now;
			if (!$normalizedFilters['includeHistory'] && $isExpired) {
				continue;
			}

			$identifier = (string) $row['identifier'];
			$parsed = $this->parseIdentifier($identifier);
			if (!$this->matchesCounterFilters($parsed, $normalizedFilters)) {
				continue;
			}
			$apiId = $parsed['apiId'];
			$limitConfig = $apiLimits[$apiId] ?? NULL;
			$effectiveLimit = $limitConfig['effectiveLimit'] ?? $defaultEffectiveLimit;

			$hits = (int) $row['hits'];
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
					'isExpired' => $isExpired,
				];
		}

		usort($counters, static function (array $a, array $b): int {
			$remainingComparison = (int) $a['remaining'] <=> (int) $b['remaining'];
			if ($remainingComparison !== 0) {
				return $remainingComparison;
			}

			$hitsComparison = (int) $b['hits'] <=> (int) $a['hits'];
			if ($hitsComparison !== 0) {
				return $hitsComparison;
			}

			return (int) $a['expiresAt'] <=> (int) $b['expiresAt'];
		});

		return $counters;
	}

	/**
	 * @param int $minExpiresAt
	 * @return array<int, array<string, mixed>>
	 */
	protected function fetchRateLimitRows(int $minExpiresAt): array {
		$queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE_NAME);

		return $queryBuilder
			->select('identifier', 'hits', 'window_start', 'expires_at')
			->from(self::TABLE_NAME)
			->where(
				$queryBuilder->expr()->gte(
					'expires_at',
					$queryBuilder->createNamedParameter($minExpiresAt, Connection::PARAM_INT)
				)
			)
			->orderBy('hits', 'DESC')
			->executeQuery()
			->fetchAllAssociative();
	}

	/**
	 * @param array<int, array<string, mixed>> $counters
	 * @param bool $includeHistory
	 * @return array<int, array<string, mixed>>
	 */
	protected function buildTopClients(array $counters, bool $includeHistory = FALSE): array {
		$relevantCounters = $includeHistory
			? $counters
			: array_filter($counters, static fn (array $counter) => !$counter['isExpired']);

		usort($relevantCounters, static fn (array $a, array $b) => $b['hits'] <=> $a['hits']);
		return array_slice($relevantCounters, 0, 10);
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

	/**
	 * @param array<string, mixed> $filters
	 * @return array{apiId: string, tenantId: string, subjectType: string, includeHistory: bool, page: int, perPage: int}
	 */
	protected function normalizeFilters(array $filters): array {
		$normalized = [
			'apiId' => trim((string) ($filters['apiId'] ?? '')),
			'tenantId' => trim((string) ($filters['tenantId'] ?? '')),
			'subjectType' => trim((string) ($filters['subjectType'] ?? '')),
			'includeHistory' => (int) ($filters['includeHistory'] ?? 0) === 1,
			'page' => max(1, (int) ($filters['page'] ?? 1)),
			'perPage' => (int) ($filters['perPage'] ?? self::DEFAULT_PER_PAGE),
		];

		if (!in_array($normalized['subjectType'], ['', 'token', 'user', 'ip', 'unknown'], TRUE)) {
			$normalized['subjectType'] = '';
		}
		if (!in_array($normalized['perPage'], self::ALLOWED_PER_PAGE, TRUE)) {
			$normalized['perPage'] = self::DEFAULT_PER_PAGE;
		}

		return $normalized;
	}

	/**
	 * @param array{apiId: string, tenantId: string, subjectType: string, includeHistory: bool, page: int, perPage: int} $filters
	 * @return bool
	 */
	protected function matchesCounterFilters(array $parsedIdentifier, array $filters): bool {
		if ($filters['apiId'] !== '' && $parsedIdentifier['apiId'] !== $filters['apiId']) {
			return FALSE;
		}

		if (
			$filters['tenantId'] !== ''
			&& !str_contains(strtolower((string) $parsedIdentifier['tenantId']), strtolower($filters['tenantId']))
		) {
			return FALSE;
		}

		if ($filters['subjectType'] !== '' && $parsedIdentifier['subjectType'] !== $filters['subjectType']) {
			return FALSE;
		}

		return TRUE;
	}

	/**
	 * @param array<int, array<string, mixed>> $counters
	 * @param int $page
	 * @param int $perPage
	 * @return array{items: array<int, array<string, mixed>>, pagination: array<string, int|bool>}
	 */
	protected function paginateCounters(array $counters, int $page, int $perPage): array {
		$totalItems = count($counters);
		$totalPages = max(1, (int) ceil($totalItems / $perPage));
		$currentPage = max(1, min($page, $totalPages));
		$offset = ($currentPage - 1) * $perPage;
		$items = array_slice($counters, $offset, $perPage);

		$fromItem = $totalItems > 0 ? ($offset + 1) : 0;
		$toItem = $totalItems > 0 ? ($offset + count($items)) : 0;

		return [
			'items' => $items,
			'pagination' => [
				'currentPage' => $currentPage,
				'totalPages' => $totalPages,
				'totalItems' => $totalItems,
				'perPage' => $perPage,
				'fromItem' => $fromItem,
				'toItem' => $toItem,
				'hasPrevious' => $currentPage > 1,
				'hasNext' => $currentPage < $totalPages,
				'previousPage' => max(1, $currentPage - 1),
				'nextPage' => min($totalPages, $currentPage + 1),
				'hasMultiplePages' => $totalPages > 1,
			],
		];
	}

	/**
	 * @param array<int, array<string, mixed>> $apiOverrides
	 * @return array<string, array<string, string>>
	 */
	protected function buildFilterOptions(array $apiOverrides): array {
		$apiOptions = ['' => 'All APIs'];
		foreach ($apiOverrides as $apiOverride) {
			$apiId = (string) ($apiOverride['apiId'] ?? '');
			if ($apiId !== '') {
				$apiOptions[$apiId] = $apiId;
			}
		}

		$perPageOptions = [];
		foreach (self::ALLOWED_PER_PAGE as $size) {
			$key = (string) $size;
			$perPageOptions[$key] = $key;
		}

		return [
			'apiId' => $apiOptions,
			'subjectType' => [
				'' => 'All subjects',
				'token' => 'Token',
				'user' => 'User',
				'ip' => 'IP',
				'unknown' => 'Unknown',
			],
			'includeHistory' => [
				'0' => 'Active only',
				'1' => 'Active + last 24h history',
			],
			'perPage' => $perPageOptions,
		];
	}
}
