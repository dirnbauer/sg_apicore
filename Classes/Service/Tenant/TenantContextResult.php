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

namespace SGalinski\SgApiCore\Service\Tenant;

use SGalinski\SgApiCore\Context\TenantContext;

/**
 * Result object for tenant resolution
 */
final class TenantContextResult {
	/**
	 * @var TenantContext|null
	 */
	private ?TenantContext $context;

	/**
	 * @var string|null
	 */
	private ?string $error;

	/**
	 * @param TenantContext|null $context
	 * @param string|null $error
	 */
	private function __construct(?TenantContext $context, ?string $error = NULL) {
		$this->context = $context;
		$this->error = $error;
	}

	/**
	 * @param TenantContext $context
	 * @return self
	 */
	public static function success(TenantContext $context): self {
		return new self($context);
	}

	/**
	 * @param string $error
	 * @return self
	 */
	public static function failure(string $error): self {
		return new self(NULL, $error);
	}

	/**
	 * @return bool
	 */
	public function isSuccess(): bool {
		return $this->context !== NULL;
	}

	/**
	 * @return TenantContext|null
	 */
	public function getContext(): ?TenantContext {
		return $this->context;
	}

	/**
	 * @return string|null
	 */
	public function getError(): ?string {
		return $this->error;
	}
}
