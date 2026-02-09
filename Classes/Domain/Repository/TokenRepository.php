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

namespace SGalinski\SgApiCore\Domain\Repository;

use Doctrine\DBAL\Exception;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\SingletonInterface;

/**
 * Repository for API Tokens
 */
class TokenRepository implements SingletonInterface {
	private const string TABLE_NAME = 'tx_apicore_token';

	protected ConnectionPool $connectionPool;

	public function __construct(ConnectionPool $connectionPool) {
		$this->connectionPool = $connectionPool;
	}

	/**
	 * Finds a token by hash, apiId, and tenantId
	 *
	 * @param string $tokenHash
	 * @param string $apiId
	 * @param string $tenantId
	 * @param int|null $siteRootPageId
	 * @param bool $includeRefreshTokens
	 * @return array|null
	 * @throws Exception
	 */
	public function findByHashApiAndTenant(
		string $tokenHash,
		string $apiId,
		string $tenantId,
		?int $siteRootPageId = NULL,
		bool $includeRefreshTokens = FALSE
	): ?array {
		$queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE_NAME);

		$constraints = [
			$queryBuilder->expr()->eq('api_id', $queryBuilder->createNamedParameter($apiId)),
			$queryBuilder->expr()->eq('tenant_id', $queryBuilder->createNamedParameter($tenantId)),
			$queryBuilder->expr()->eq('token_hash', $queryBuilder->createNamedParameter($tokenHash)),
			$queryBuilder->expr()->eq('revoked_at', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT))
		];

		if (!$includeRefreshTokens) {
			$constraints[] = $queryBuilder->expr()->eq(
				'is_refresh_token',
				$queryBuilder->createNamedParameter(0, Connection::PARAM_INT)
			);
		}

		if ($siteRootPageId !== NULL) {
			$constraints[] = $queryBuilder->expr()->or(
				$queryBuilder->expr()->eq(
					'pid',
					$queryBuilder->createNamedParameter($siteRootPageId, Connection::PARAM_INT)
				),
				$queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT))
			);
		}

		return $queryBuilder
			->select('*')
			->from(self::TABLE_NAME)
			->where(...$constraints)
			->executeQuery()
			->fetchAssociative() ?: NULL;
	}

	/**
	 * Finds a token by hash globally (across all APIs and tenants)
	 *
	 * @param string $tokenHash
	 * @return array|null
	 * @throws Exception
	 */
	public function findByHashGlobally(string $tokenHash): ?array {
		$queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE_NAME);

		return $queryBuilder
			->select('*')
			->from(self::TABLE_NAME)
			->where(
				$queryBuilder->expr()->eq('token_hash', $queryBuilder->createNamedParameter($tokenHash)),
				$queryBuilder->expr()->eq('revoked_at', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT))
			)
			->executeQuery()
			->fetchAssociative() ?: NULL;
	}

	/**
	 * Finds tokens by apiId, and tenantId
	 *
	 * @param string $apiId
	 * @param string $tenantId
	 * @param int|null $siteRootPageId
	 * @return array
	 * @throws Exception
	 */
	public function findByApiAndTenant(string $apiId, string $tenantId, ?int $siteRootPageId = NULL): array {
		$queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE_NAME);

		$constraints = [
			$queryBuilder->expr()->eq('api_id', $queryBuilder->createNamedParameter($apiId)),
			$queryBuilder->expr()->eq('tenant_id', $queryBuilder->createNamedParameter($tenantId)),
			$queryBuilder->expr()->eq('revoked_at', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT))
		];

		if ($siteRootPageId !== NULL) {
			$constraints[] = $queryBuilder->expr()->or(
				$queryBuilder->expr()->eq(
					'pid',
					$queryBuilder->createNamedParameter($siteRootPageId, Connection::PARAM_INT)
				),
				$queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT))
			);
		}

		return $queryBuilder
			->select('*')
			->from(self::TABLE_NAME)
			->where(...$constraints)
			->executeQuery()
			->fetchAllAssociative();
	}

	/**
	 * Finds tokens by filters
	 *
	 * @param array $filters
	 * @return array
	 * @throws Exception
	 */
	public function findAllWithFilters(array $filters = []): array {
		$queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE_NAME);

		$query = $queryBuilder
			->select('*')
			->from(self::TABLE_NAME);

		if (isset($filters['apiId']) && $filters['apiId'] !== '') {
			$query->andWhere(
				$queryBuilder->expr()->eq('api_id', $queryBuilder->createNamedParameter($filters['apiId']))
			);
		}
		if (isset($filters['tenantId']) && $filters['tenantId'] !== '') {
			$query->andWhere(
				$queryBuilder->expr()->eq('tenant_id', $queryBuilder->createNamedParameter($filters['tenantId']))
			);
		}
		if (isset($filters['isRefreshToken'])) {
			$query->andWhere(
				$queryBuilder->expr()->eq(
					'is_refresh_token',
					$queryBuilder->createNamedParameter((int) $filters['isRefreshToken'], Connection::PARAM_INT)
				)
			);
		}
		if (isset($filters['isUserToken'])) {
			if ((int) $filters['isUserToken'] === 1) {
				$query->andWhere($queryBuilder->expr()->gt('user_id', 0));
			} else {
				$query->andWhere($queryBuilder->expr()->eq('user_id', 0));
			}
		}
		if (isset($filters['status']) && $filters['status'] !== '') {
			if ($filters['status'] === 'revoked') {
				$query->andWhere($queryBuilder->expr()->gt('revoked_at', 0));
			} elseif ($filters['status'] === 'expired') {
				$query->andWhere($queryBuilder->expr()->gt('expires_at', 0));
				$query->andWhere($queryBuilder->expr()->lt('expires_at', time()));
			} elseif ($filters['status'] === 'active') {
				$query->andWhere($queryBuilder->expr()->eq('revoked_at', 0));
				$query->andWhere(
					$queryBuilder->expr()->or(
						$queryBuilder->expr()->eq('expires_at', 0),
						$queryBuilder->expr()->gt('expires_at', time())
					)
				);
			}
		}

		return $query->orderBy('uid', 'DESC')->executeQuery()->fetchAllAssociative();
	}

	/**
	 * Revokes a token by UID
	 *
	 * @param int $uid
	 * @return void
	 */
	public function revoke(int $uid): void {
		$queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE_NAME);

		$queryBuilder
			->update(self::TABLE_NAME)
			->set('revoked_at', time())
			->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)))
			->executeStatement();
	}

	/**
	 * Updates the hash of a token
	 *
	 * @param int $uid
	 * @param string $tokenHash
	 * @return void
	 */
	public function updateTokenHash(int $uid, string $tokenHash): void {
		$queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE_NAME);

		$queryBuilder
			->update(self::TABLE_NAME)
			->set('token_hash', $tokenHash)
			->set('tstamp', time())
			->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)))
			->executeStatement();
	}

	/**
	 * Updates the last used timestamp of a token
	 *
	 * @param int $uid
	 * @return void
	 */
	public function updateLastUsed(int $uid): void {
		$queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE_NAME);

		$queryBuilder
			->update(self::TABLE_NAME)
			->set('last_used_at', time())
			->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)))
			->executeStatement();
	}
}
