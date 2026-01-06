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

namespace SGalinski\SgApiCore\Domain\Repository;

use Doctrine\DBAL\Exception;
use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Repository for API Tokens
 */
class TokenRepository implements SingletonInterface {
	private const string TABLE_NAME = 'tx_apicore_token';

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
		$queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
			?->getQueryBuilderForTable(self::TABLE_NAME);

		$constraints = [
			$queryBuilder->expr()->eq('api_id', $queryBuilder->createNamedParameter($apiId)),
			$queryBuilder->expr()->eq('tenant_id', $queryBuilder->createNamedParameter($tenantId)),
			$queryBuilder->expr()->eq('token_hash', $queryBuilder->createNamedParameter($tokenHash)),
			$queryBuilder->expr()->eq('revoked_at', $queryBuilder->createNamedParameter(0, ParameterType::INTEGER))
		];

		if (!$includeRefreshTokens) {
			$constraints[] = $queryBuilder->expr()->eq(
				'is_refresh_token',
				$queryBuilder->createNamedParameter(0, ParameterType::INTEGER)
			);
		}

		if ($siteRootPageId !== NULL) {
			$constraints[] = $queryBuilder->expr()->eq(
				'pid',
				$queryBuilder->createNamedParameter($siteRootPageId, ParameterType::INTEGER)
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
	 * Finds tokens by apiId, and tenantId
	 *
	 * @param string $apiId
	 * @param string $tenantId
	 * @param int|null $siteRootPageId
	 * @return array
	 * @throws Exception
	 */
	public function findByApiAndTenant(string $apiId, string $tenantId, ?int $siteRootPageId = NULL): array {
		$queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
			?->getQueryBuilderForTable(self::TABLE_NAME);

		$constraints = [
			$queryBuilder->expr()->eq('api_id', $queryBuilder->createNamedParameter($apiId)),
			$queryBuilder->expr()->eq('tenant_id', $queryBuilder->createNamedParameter($tenantId)),
			$queryBuilder->expr()->eq('revoked_at', $queryBuilder->createNamedParameter(0, ParameterType::INTEGER))
		];

		if ($siteRootPageId !== NULL) {
			$constraints[] = $queryBuilder->expr()->eq(
				'pid',
				$queryBuilder->createNamedParameter($siteRootPageId, ParameterType::INTEGER)
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
	 * Updates the last used timestamp of a token
	 *
	 * @param int $uid
	 * @return void
	 */
	public function updateLastUsed(int $uid): void {
		$queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
			?->getQueryBuilderForTable(self::TABLE_NAME);

		$queryBuilder
			->update(self::TABLE_NAME)
			->set('last_used_at', time())
			->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, ParameterType::INTEGER)))
			->executeStatement();
	}
}
