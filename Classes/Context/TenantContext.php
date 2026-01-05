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

namespace SGalinski\SgApiCore\Context;

/**
 * Immutable Value Object for the Tenant Context
 */
final class TenantContext {
	/**
	 * @var string
	 */
	private string $tenantId;

	/**
	 * @var string|null
	 */
	private ?string $siteIdentifier;

	/**
	 * @var string|null
	 */
	private ?string $baseHost;

	/**
	 * @param string $tenantId
	 * @param string|null $siteIdentifier
	 * @param string|null $baseHost
	 */
	public function __construct(string $tenantId, ?string $siteIdentifier = NULL, ?string $baseHost = NULL) {
		$this->tenantId = $tenantId;
		$this->siteIdentifier = $siteIdentifier;
		$this->baseHost = $baseHost;
	}

	/**
	 * @return string
	 */
	public function getTenantId(): string {
		return $this->tenantId;
	}

	/**
	 * @return string|null
	 */
	public function getSiteIdentifier(): ?string {
		return $this->siteIdentifier;
	}

	/**
	 * @return string|null
	 */
	public function getBaseHost(): ?string {
		return $this->baseHost;
	}
}
