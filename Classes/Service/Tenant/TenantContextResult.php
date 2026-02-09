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
