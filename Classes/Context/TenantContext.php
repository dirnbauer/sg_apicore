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

use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * Immutable Value Object for the Tenant Context
 */
final readonly class TenantContext {
	/**
	 * @var string
	 */
	private string $tenantId;

	/**
	 * @var string|null
	 */
	private ?string $siteIdentifier;

	/**
	 * @var int|null
	 */
	private ?int $siteRootPageId;

	/**
	 * @var int|null
	 */
	private ?int $languageId;

	/**
	 * @var Site|null
	 */
	private ?Site $site;

	/**
	 * @param string $tenantId
	 * @param string|null $siteIdentifier
	 * @param int|null $siteRootPageId
	 * @param Site|null $site
	 * @param int|null $languageId
	 */
	public function __construct(
		string $tenantId,
		?string $siteIdentifier = NULL,
		?int $siteRootPageId = NULL,
		?Site $site = NULL,
		?int $languageId = NULL
	) {
		$this->tenantId = $tenantId;
		$this->siteIdentifier = $siteIdentifier;
		$this->siteRootPageId = $siteRootPageId;
		$this->site = $site;
		$this->languageId = $languageId;
	}

	/**
	 * @return int|null
	 */
	public function getLanguageId(): ?int {
		return $this->languageId;
	}

	/**
	 * @return int|null
	 */
	public function getSiteRootPageId(): ?int {
		return $this->siteRootPageId ?? $this->site?->getRootPageId();
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
		return $this->siteIdentifier ?? $this->site?->getIdentifier();
	}

	/**
	 * @return Site|null
	 */
	public function getSite(): ?Site {
		return $this->site;
	}
}
