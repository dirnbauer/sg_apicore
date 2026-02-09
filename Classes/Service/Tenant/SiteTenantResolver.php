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

use Psr\Http\Message\ServerRequestInterface;
use SGalinski\SgApiCore\Configuration\ExtensionConfiguration;
use SGalinski\SgApiCore\Context\TenantContext;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;

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

		/** @var SiteLanguage|null $language */
		$language = $request->getAttribute('language');

		$context = new TenantContext(
			$tenantId,
			$site->getIdentifier(),
			$site->getRootPageId(),
			$site,
			$language?->getLanguageId()
		);

		return TenantContextResult::success($context);
	}
}
