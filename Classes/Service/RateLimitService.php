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

use Doctrine\DBAL\Exception;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\SingletonInterface;

/**
 * Simple DB-backed rate limiter (fixed window)
 */
class RateLimitService implements SingletonInterface {
	protected const string TABLE_NAME = 'tx_apicore_rate_limit';

	public function __construct(protected ConnectionPool $connectionPool) {
	}

	/**
	 * Consumes one request for the given identifier
	 *
	 * @param string $identifier
	 * @param int $limit
	 * @param int $windowSeconds
	 * @param int $burst
	 * @return array{allowed: bool, limit: int, remaining: int, reset: int, burst: int}
	 * @throws Exception
	 */
	public function consume(string $identifier, int $limit, int $windowSeconds, int $burst = 0): array {
		$now = time();
		$windowStart = $now - ($now % $windowSeconds);
		$expiresAt = $windowStart + $windowSeconds;
		$burst = max(0, $burst);
		$effectiveLimit = $limit + $burst;

		$connection = $this->connectionPool->getConnectionForTable(self::TABLE_NAME);
		$connection->beginTransaction();
		try {
			$row = $connection->fetchAssociative(
				'SELECT hits, window_start FROM ' . self::TABLE_NAME . ' WHERE identifier = ?',
				[$identifier]
			);

			$allowed = TRUE;
			if (\is_array($row) && (int) $row['window_start'] === $windowStart) {
				$hits = (int) $row['hits'];
				if ($hits >= $effectiveLimit) {
					$allowed = FALSE;
				} else {
					$hits++;
					$connection->update(self::TABLE_NAME, [
						'hits' => $hits,
						'expires_at' => $expiresAt,
					], ['identifier' => $identifier]);
				}
			} else {
				$hits = 1;
				if (\is_array($row)) {
					$connection->update(self::TABLE_NAME, [
						'hits' => $hits,
						'window_start' => $windowStart,
						'expires_at' => $expiresAt,
					], ['identifier' => $identifier]);
				} else {
					$connection->insert(self::TABLE_NAME, [
						'identifier' => $identifier,
						'window_start' => $windowStart,
						'hits' => $hits,
						'expires_at' => $expiresAt,
					]);
				}
			}

			$connection->commit();
		} catch (\Throwable) {
			$connection->rollBack();
			// Fail open on limiter errors
			return [
				'allowed' => TRUE,
				'limit' => $effectiveLimit,
				'remaining' => $effectiveLimit,
				'reset' => $expiresAt,
				'burst' => $burst,
			];
		}

		return [
			'allowed' => $allowed,
			'limit' => $effectiveLimit,
			'remaining' => max(0, $effectiveLimit - $hits),
			'reset' => $expiresAt,
			'burst' => $burst,
		];
	}
}
