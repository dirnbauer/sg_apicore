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

namespace SGalinski\SgApiCore\Security;

use Psr\Http\Message\ServerRequestInterface;
use SGalinski\SgApiCore\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Legacy Token Provider for sg_rest compatibility
 * Checks against fe_users.tx_sgrest_auth_token
 */
class LegacyTokenProvider implements LoginProviderInterface {
	use TokenExtractionTrait;

	/**
	 * @var ConnectionPool
	 */
	protected ConnectionPool $connectionPool;

	/**
	 * @var ExtensionConfiguration
	 */
	protected ExtensionConfiguration $extensionConfiguration;

	/**
	 * @param ConnectionPool $connectionPool
	 * @param ExtensionConfiguration $extensionConfiguration
	 */
	public function __construct(ConnectionPool $connectionPool, ExtensionConfiguration $extensionConfiguration) {
		$this->connectionPool = $connectionPool;
		$this->extensionConfiguration = $extensionConfiguration;
	}

	/**
	 * Authenticates the request and returns an AuthContext if successful
	 *
	 * @param ServerRequestInterface $request
	 * @param string $apiId
	 * @param string $tenantId
	 * @param array $activeProviders
	 * @return AuthContext|null
	 */
	public function authenticate(
		ServerRequestInterface $request,
		string $apiId,
		string $tenantId,
		array $activeProviders = []
	): ?AuthContext {
		if (!$this->extensionConfiguration->isActivateLegacySupport()) {
			return NULL;
		}

		// Support both standard Bearer and legacy 'bearertoken' header or query param
		$token = $this->extractToken($request);
		if ($token === '') {
			return NULL;
		}

		$queryBuilder = $this->connectionPool->getQueryBuilderForTable('fe_users');
		$user = $queryBuilder->select('uid', 'username')
			->from('fe_users')
			->where(
				$queryBuilder->expr()->eq('tx_sgrest_auth_token', $queryBuilder->createNamedParameter($token)),
				$queryBuilder->expr()->eq('disable', $queryBuilder->createNamedParameter(0, \PDO::PARAM_INT)),
				$queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, \PDO::PARAM_INT))
			)
			->executeQuery()
			->fetchAssociative();

		if (!$user) {
			return NULL;
		}

		return new AuthContext(
			apiId: $apiId,
			tenantId: $tenantId,
			tokenUid: 'legacy-' . $user['uid'],
			scopes: ['legacy', 'read', 'write'], // Legacy tokens often had full access or implicit scopes
			userId: (int) $user['uid']
		);
	}
}
