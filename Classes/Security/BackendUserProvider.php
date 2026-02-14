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

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\Exception\AspectNotFoundException;
use TYPO3\CMS\Core\Context\Exception\AspectPropertyNotFoundException;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Authentication provider that checks for a valid TYPO3 backend user session
 */
class BackendUserProvider implements LoginProviderInterface {
	/**
	 * Authenticates the request by checking for a valid backend user session
	 *
	 * @param ServerRequestInterface $request
	 * @param string $apiId
	 * @param string|null $tenantId
	 * @param array $activeProviders
	 * @return AuthContext|null
	 * @throws AspectNotFoundException
	 * @throws AspectPropertyNotFoundException
	 */
	public function authenticate(
		ServerRequestInterface $request,
		string $apiId,
		?string $tenantId,
		array $activeProviders = []
	): ?AuthContext {
		$context = GeneralUtility::makeInstance(Context::class);
		$beUserAspect = $context->getAspect('backend.user');

		if ($beUserAspect->get('isLoggedIn')) {
			$userId = (int) $beUserAspect->get('id');
			return new AuthContext(
				$apiId,
				$tenantId ?? '',
				tokenUid: 'be-' . $userId,
				scopes: ['backend', 'partner:read', 'partner:write', 'user'],
				userId: $userId
			);
		}

		return NULL;
	}
}
