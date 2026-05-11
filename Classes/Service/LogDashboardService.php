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

use Psr\Log\LogLevel;
use SGalinski\SgApiCore\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Core\Environment;

/**
 * Builds a dashboard view from the sg_apicore log file
 */
class LogDashboardService {
	protected const string DEFAULT_LOG_FILE = '/log/sg_apicore.log';
	protected const int CACHE_TTL = 60;

	protected FrontendInterface $cache;

	public function __construct(
		protected readonly ExtensionConfiguration $extensionConfiguration,
		protected readonly ApiRegistry $apiRegistry,
		CacheManager $cacheManager
	) {
		$this->cache = $cacheManager->getCache('sg_apicore_dashboard');
	}

	/**
	 * @param int $hours
	 * @param int $maxLines
	 * @param bool $includeErrors
	 * @return array<string, mixed>
	 */
	public function getDashboardData(int $hours, int $maxLines, bool $includeErrors): array {
		$startTime = microtime(TRUE);
		$logFilePath = $this->resolveLogFilePath();
		$cutoff = time() - ($hours * 3600);
		$cacheKey = 'log_dashboard_' . $hours . '_' . $maxLines . '_' . (int) $includeErrors;
		$fileMtime = $logFilePath !== '' && is_file($logFilePath) ? filemtime($logFilePath) : 0;

		$cached = $this->cache->get($cacheKey);
		if (\is_array($cached)
			&& isset($cached['cacheTimestamp'], $cached['fileMtime'])
			&& $cached['fileMtime'] === $fileMtime
			&& (time() - (int) $cached['cacheTimestamp']) <= self::CACHE_TTL
		) {
			$cached['metrics'] = array_merge($cached['metrics'] ?? [], [
				'cacheHit' => TRUE,
				'cacheAgeSeconds' => time() - (int) $cached['cacheTimestamp'],
			]);
			return $cached;
		}

		$baseData = [
			'loggingEnabled' => $this->extensionConfiguration->isLoggingEnabled(),
			'logFilePath' => $logFilePath,
			'logFileReadable' => $logFilePath !== '' && is_readable($logFilePath),
			'logFileSize' => $logFilePath !== '' && is_file($logFilePath) ? filesize($logFilePath) : 0,
			'logEntries' => [],
			'requestEntries' => [],
			'statusBuckets' => [],
			'timeBuckets' => [],
			'topEndpoints' => [],
			'topTenants' => [],
			'topApis' => [],
			'slowRequests' => [],
			'recentErrors' => [],
			'summary' => [
				'totalRequests' => 0,
				'errorRequests' => 0,
				'avgDuration' => NULL,
				'p95Duration' => NULL,
				'timeRangeStart' => $cutoff,
				'timeRangeEnd' => time(),
			],
			'metrics' => [
				'cacheHit' => FALSE,
				'cacheAgeSeconds' => NULL,
				'parseDurationMs' => NULL,
			],
			'maxLines' => $maxLines,
		];

		if (!$baseData['logFileReadable']) {
			$baseData['errorMessage'] = 'Log file not found or not readable.';
			$baseData['metrics']['parseDurationMs'] = $this->getDurationMs($startTime);
			$baseData['cacheTimestamp'] = time();
			$baseData['fileMtime'] = $fileMtime;
			$this->cache->set($cacheKey, $baseData, [], self::CACHE_TTL);
			return $baseData;
		}

		$lines = $this->readLastLines($logFilePath, $maxLines);
		$entries = [];
		foreach ($lines as $line) {
			$parsed = $this->parseLogLine($line);
			if ($parsed === NULL || $parsed['timestamp'] < $cutoff) {
				continue;
			}
			$entries[] = $parsed;
		}

		$requestEntries = array_values(array_filter($entries, static function (array $entry) {
			return $entry['isRequest'];
		}));

		$summary = $this->buildSummary($requestEntries, $cutoff);
		$statusBuckets = $this->buildStatusBuckets($requestEntries);
		$timeBuckets = $this->buildTimeBuckets($requestEntries);
		$topEndpoints = $this->buildTopList($requestEntries, 'endpoint');
		$topTenants = $this->buildTopList($requestEntries, 'tenantId');
		$registeredApis = array_keys($this->apiRegistry->getApis());
		$topApis = $this->buildTopList(
			$requestEntries,
			'apiId',
			['unknown', 'global'],
			$registeredApis !== [] ? $registeredApis : NULL
		);
		$slowRequests = $this->buildSlowRequests($requestEntries);
		$recentErrors = $this->buildRecentErrors($entries, $includeErrors);

		$result = array_merge($baseData, [
			'logEntries' => $entries,
			'requestEntries' => $requestEntries,
			'statusBuckets' => $statusBuckets,
			'timeBuckets' => $timeBuckets,
			'topEndpoints' => $topEndpoints,
			'topTenants' => $topTenants,
			'topApis' => $topApis,
			'slowRequests' => $slowRequests,
			'recentErrors' => $recentErrors,
			'summary' => $summary,
			'processedLines' => \count($lines),
		]);
		$result['metrics'] = [
			'cacheHit' => FALSE,
			'cacheAgeSeconds' => NULL,
			'parseDurationMs' => $this->getDurationMs($startTime),
		];
		$result['cacheTimestamp'] = time();
		$result['fileMtime'] = $fileMtime;
		$this->cache->set($cacheKey, $result, [], self::CACHE_TTL);
		return $result;
	}

	/**
	 * @return string
	 */
	protected function resolveLogFilePath(): string {
		$config = $GLOBALS['TYPO3_CONF_VARS']['LOG']['SGalinski']['SgApiCore']['Service']['LogService']['writerConfiguration'] ?? [];
		$levels = array_keys($config);
		$levels[] = LogLevel::INFO;

		foreach ($levels as $level) {
			$writerConfig = $config[$level] ?? [];
			foreach ($writerConfig as $writerOptions) {
				if (isset($writerOptions['logFile']) && \is_string($writerOptions['logFile'])) {
					return $writerOptions['logFile'];
				}
			}
		}

		return Environment::getVarPath() . self::DEFAULT_LOG_FILE;
	}

	/**
	 * @param string $filePath
	 * @param int $maxLines
	 * @return array<int, string>
	 */
	protected function readLastLines(string $filePath, int $maxLines): array {
		$handle = fopen($filePath, 'rb');
		if (!\is_resource($handle)) {
			return [];
		}

		$position = max(0, filesize($filePath));
		$buffer = '';
		$lines = [];
		$chunkSize = 8192;

		while ($position > 0 && \count($lines) <= $maxLines) {
			$readSize = min($chunkSize, $position);
			$position -= $readSize;
			fseek($handle, $position);
			$buffer = fread($handle, $readSize) . $buffer;

			$parts = explode("\n", $buffer);
			$buffer = array_shift($parts);
			if (!empty($parts)) {
				$lines = array_merge($parts, $lines);
			}
		}

		if ($buffer !== '') {
			array_unshift($lines, $buffer);
		}

		fclose($handle);

		$lines = array_values(array_filter(array_map('trim', $lines), static fn ($line) => $line !== ''));
		if (\count($lines) > $maxLines) {
			$lines = \array_slice($lines, -$maxLines);
		}

		return $lines;
	}

	/**
	 * @param string $line
	 * @return array<string, mixed>|null
	 */
	protected function parseLogLine(string $line): ?array {
		$pattern = '/^(?<date>[^[]+)\s+\[(?<level>[A-Z]+)\]\s+request="(?<requestId>[^"]*)"\s+component="(?<component>[^"]*)":\s+(?<message>.*?)(?:\s+-\s+(?<context>\{.*\}))?$/';
		if (!preg_match($pattern, $line, $matches)) {
			return NULL;
		}

		$timestamp = strtotime(trim($matches['date'])) ?: 0;
		$context = [];
		if (!empty($matches['context'])) {
			$decoded = json_decode($matches['context'], TRUE);
			if (\is_array($decoded)) {
				$context = $decoded;
			}
		}

		$duration = $this->parseDuration($context['duration'] ?? '');
		$method = $context['method'] ?? NULL;
		$path = $context['path'] ?? NULL;
		$status = isset($context['status']) ? (int) $context['status'] : NULL;
		$endpoint = $method && $path ? strtoupper((string) $method) . ' ' . $path : NULL;
		$isRequest = $endpoint !== NULL && $status !== NULL;

		return [
			'timestamp' => $timestamp,
			'level' => $matches['level'],
			'requestId' => $matches['requestId'],
			'component' => $matches['component'],
			'message' => trim($matches['message']),
			'context' => $context,
			'apiId' => $context['apiId'] ?? 'unknown',
			'tenantId' => $context['tenantId'] ?? 'unknown',
			'method' => $method,
			'path' => $path,
			'status' => $status,
			'endpoint' => $endpoint,
			'durationMs' => $duration,
			'isRequest' => $isRequest,
		];
	}

	/**
	 * @param string $duration
	 * @return float|null
	 */
	protected function parseDuration(string $duration): ?float {
		if ($duration === '') {
			return NULL;
		}
		if (preg_match('/^(?<value>\d+(?:\.\d+)?)ms$/', $duration, $matches)) {
			return (float) $matches['value'];
		}
		return NULL;
	}

	/**
	 * @param float $startTime
	 * @return float
	 */
	protected function getDurationMs(float $startTime): float {
		return (microtime(TRUE) - $startTime) * 1000;
	}

	/**
	 * @param array<int, array<string, mixed>> $requests
	 * @param int $cutoff
	 * @return array<string, mixed>
	 */
	protected function buildSummary(array $requests, int $cutoff): array {
		$total = \count($requests);
		$errorCount = 0;
		$durations = [];

		foreach ($requests as $entry) {
			$status = (int) ($entry['status'] ?? 0);
			if ($status >= 400) {
				$errorCount++;
			}
			if ($entry['durationMs'] !== NULL) {
				$durations[] = $entry['durationMs'];
			}
		}

		sort($durations);
		$avg = NULL;
		$p95 = NULL;
		if (\count($durations) > 0) {
			$avg = array_sum($durations) / \count($durations);
			$index = (int) floor(0.95 * (\count($durations) - 1));
			$p95 = $durations[$index];
		}

		return [
			'totalRequests' => $total,
			'errorRequests' => $errorCount,
			'avgDuration' => $avg,
			'p95Duration' => $p95,
			'timeRangeStart' => $cutoff,
			'timeRangeEnd' => time(),
		];
	}

	/**
	 * @param array<int, array<string, mixed>> $requests
	 * @return array<int, array<string, mixed>>
	 */
	protected function buildStatusBuckets(array $requests): array {
		$buckets = [
			'2xx' => 0,
			'3xx' => 0,
			'4xx' => 0,
			'5xx' => 0,
			'other' => 0,
		];

		foreach ($requests as $entry) {
			$status = (int) ($entry['status'] ?? 0);
			if ($status >= 200 && $status < 300) {
				$buckets['2xx']++;
			} elseif ($status >= 300 && $status < 400) {
				$buckets['3xx']++;
			} elseif ($status >= 400 && $status < 500) {
				$buckets['4xx']++;
			} elseif ($status >= 500 && $status < 600) {
				$buckets['5xx']++;
			} else {
				$buckets['other']++;
			}
		}

		$result = [];
		$max = max($buckets);
		foreach ($buckets as $label => $count) {
			$percent = $max > 0 ? round(($count / $max) * 100, 2) : 0;
			$result[] = [
				'label' => $label,
				'count' => $count,
				'percent' => $percent,
			];
		}

		return $result;
	}

	/**
	 * @param array<int, array<string, mixed>> $requests
	 * @return array<int, array<string, mixed>>
	 */
	protected function buildTimeBuckets(array $requests): array {
		$counts = [];
		foreach ($requests as $entry) {
			if (!isset($entry['timestamp'])) {
				continue;
			}
			$bucket = date('Y-m-d H:00', (int) $entry['timestamp']);
			$counts[$bucket] = ($counts[$bucket] ?? 0) + 1;
		}

		ksort($counts);
		$max = $counts ? max($counts) : 0;
		$result = [];
		foreach ($counts as $bucket => $count) {
			$percent = $max > 0 ? round(($count / $max) * 100, 2) : 0;
			$result[] = [
				'label' => $bucket,
				'count' => $count,
				'percent' => $percent,
			];
		}

		return $result;
	}

	/**
	 * @param array<int, array<string, mixed>> $requests
	 * @param string $field
	 * @param array<int, string> $ignoreValues
	 * @param array<int, string>|null $allowedValues
	 * @return array<int, array<string, mixed>>
	 */
	protected function buildTopList(
		array $requests,
		string $field,
		array $ignoreValues = [],
		?array $allowedValues = NULL
	): array {
		$counts = [];
		$ignoreLookup = array_flip($ignoreValues);
		$allowedLookup = $allowedValues !== NULL ? array_flip($allowedValues) : [];
		foreach ($requests as $entry) {
			$value = $entry[$field] ?? 'unknown';
			if ($value === '') {
				$value = 'unknown';
			}
			if (isset($ignoreLookup[$value])) {
				continue;
			}
			if ($allowedValues !== NULL && !isset($allowedLookup[$value])) {
				continue;
			}
			$counts[$value] = ($counts[$value] ?? 0) + 1;
		}

		arsort($counts);
		$max = $counts ? reset($counts) : 0;
		$result = [];
		foreach (\array_slice($counts, 0, 10, TRUE) as $label => $count) {
			$percent = $max > 0 ? round(($count / $max) * 100, 2) : 0;
			$result[] = [
				'label' => (string) $label,
				'count' => $count,
				'percent' => $percent,
			];
		}

		return $result;
	}

	/**
	 * @param array<int, array<string, mixed>> $requests
	 * @return array<int, array<string, mixed>>
	 */
	protected function buildSlowRequests(array $requests): array {
		$filtered = array_filter($requests, static fn (array $entry) => $entry['durationMs'] !== NULL);
		usort($filtered, static fn (array $a, array $b) => $b['durationMs'] <=> $a['durationMs']);
		$top = \array_slice($filtered, 0, 10);
		foreach ($top as &$entry) {
			$durationMs = (float) $entry['durationMs'];
			$entry['durationLabel'] = $this->formatDurationLabel($durationMs);
			$entry['durationIsCritical'] = $durationMs >= 10000;
		}
		unset($entry);
		return $top;
	}

	/**
	 * @param float $durationMs
	 * @return string
	 */
	protected function formatDurationLabel(float $durationMs): string {
		if ($durationMs >= 60000) {
			$value = $durationMs / 60000;
			$unit = 'min';
		} elseif ($durationMs >= 1000) {
			$value = $durationMs / 1000;
			$unit = 's';
		} else {
			$value = $durationMs;
			$unit = 'ms';
		}

		return number_format($value, 2, '.', '') . ' ' . $unit;
	}

	/**
	 * @param array<int, array<string, mixed>> $entries
	 * @param bool $includeErrors
	 * @return array<int, array<string, mixed>>
	 */
	protected function buildRecentErrors(array $entries, bool $includeErrors): array {
		$errors = array_filter($entries, static function (array $entry) use ($includeErrors) {
			$status = (int) ($entry['status'] ?? 0);
			if ($status >= 400) {
				return TRUE;
			}
			if (!$includeErrors) {
				return FALSE;
			}
			return \in_array($entry['level'], ['ERROR', 'CRITICAL'], TRUE);
		});
		usort($errors, static fn (array $a, array $b) => $b['timestamp'] <=> $a['timestamp']);
		return \array_slice($errors, 0, 10);
	}
}
