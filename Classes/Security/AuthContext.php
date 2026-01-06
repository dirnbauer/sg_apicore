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

/**
 * Value object for authentication context
 */
final readonly class AuthContext {
	/**
	 * @param string $apiId
	 * @param string $tenantId
	 * @param int|string|null $tokenUid
	 * @param array $scopes
	 * @param int|null $userId
	 */
	public function __construct(
		public string $apiId,
		public string $tenantId,
		public int|string|NULL $tokenUid = NULL,
		public array $scopes = [],
		public ?int $userId = NULL
	) {
	}

	/**
	 * @return int|null
	 */
	public function getUserId(): ?int {
		return $this->userId;
	}

	/**
	 * @return string
	 */
	public function getApiId(): string {
		return $this->apiId;
	}

	/**
	 * @return string
	 */
	public function getTenantId(): string {
		return $this->tenantId;
	}

	/**
	 * @return int|string|null
	 */
	public function getTokenUid(): int|string|NULL {
		return $this->tokenUid;
	}

	/**
	 * @return array
	 */
	public function getScopes(): array {
		return $this->scopes;
	}

	/**
	 * Checks if the context has a specific scope
	 *
	 * @param string $scope
	 * @return bool
	 */
	public function hasScope(string $scope): bool {
		return in_array($scope, $this->scopes, TRUE);
	}
}
