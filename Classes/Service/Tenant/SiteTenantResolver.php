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

use Psr\Http\Message\ServerRequestInterface;
use SGalinski\SgApiCore\Configuration\ExtensionConfiguration;
use SGalinski\SgApiCore\Context\TenantContext;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * Resolves the tenant from the TYPO3 Site
 */
class SiteTenantResolver implements TenantResolverInterface {
	/**
	 * @var ExtensionConfiguration
	 */
	protected ExtensionConfiguration $extensionConfiguration;

	/**
	 * @param ExtensionConfiguration $extensionConfiguration
	 */
	public function __construct(ExtensionConfiguration $extensionConfiguration) {
		$this->extensionConfiguration = $extensionConfiguration;
	}

	/**
	 * @param ServerRequestInterface $request
	 * @return TenantContextResult
	 */
	public function resolve(ServerRequestInterface $request): TenantContextResult {
		/** @var Site|null $site */
		$site = $request->getAttribute('site');
		if (!$site instanceof Site) {
			return TenantContextResult::failure('site_not_found');
		}

		$source = $this->extensionConfiguration->getSiteTenantIdSource();
		$tenantId = match ($source) {
			'baseHost' => $site->getBase()->getHost(),
			'rootPageId' => (string) $site->getRootPageId(),
			default => $site->getIdentifier(),
		};

		if ($tenantId === '') {
			return TenantContextResult::failure('tenant_id_empty');
		}

		$context = new TenantContext(
			$tenantId,
			$site->getIdentifier(),
			$site->getRootPageId(),
			$site
		);

		return TenantContextResult::success($context);
	}
}
