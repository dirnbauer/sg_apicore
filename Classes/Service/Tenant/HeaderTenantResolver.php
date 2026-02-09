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
use SGalinski\SgApiCore\Context\TenantContext;

/**
 * Resolves the tenant from a header (X-Tenant-Id)
 */
class HeaderTenantResolver implements TenantResolverInterface {
	/**
	 * @param ServerRequestInterface $request
	 * @return TenantContextResult
	 */
	public function resolve(ServerRequestInterface $request): TenantContextResult {
		$tenantId = $request->getHeaderLine('X-Tenant-Id');
		if ($tenantId === '') {
			return TenantContextResult::failure('header_missing');
		}

		$context = new TenantContext($tenantId);
		return TenantContextResult::success($context);
	}
}
