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

namespace SGalinski\SgApiCore\Event;

use SGalinski\SgApiCore\Context\TenantContext;

/**
 * Event triggered after successful user authentication (password check passed).
 * Allows listeners to perform additional validation and block the login by throwing an exception.
 */
class AfterUserAuthenticationEvent {
	/**
	 * @param array $user The user record from fe_users
	 * @param TenantContext|null $tenantContext
	 */
	public function __construct(
		protected array $user,
		protected ?TenantContext $tenantContext = NULL
	) {
	}

	/**
	 * @return array
	 */
	public function getUser(): array {
		return $this->user;
	}

	/**
	 * @return TenantContext|null
	 */
	public function getTenantContext(): ?TenantContext {
		return $this->tenantContext;
	}
}
