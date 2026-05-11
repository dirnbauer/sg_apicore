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
		return \in_array($scope, $this->scopes, TRUE);
	}
}
