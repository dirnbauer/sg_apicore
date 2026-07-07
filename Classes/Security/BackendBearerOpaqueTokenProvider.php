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

use Doctrine\DBAL\Exception;
use Psr\Http\Message\ServerRequestInterface;
use SGalinski\SgApiCore\Configuration\ExtensionConfiguration;
use SGalinski\SgApiCore\Context\TenantContext;
use SGalinski\SgApiCore\Domain\Repository\TokenRepository;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Bearer token provider bound to a TYPO3 backend user.
 *
 * Tokens carry a be_user_uid; a matching token boots the referenced backend
 * user (group data, optional workspace via the X-TYPO3-Workspace header or
 * ?workspace= parameter) into $GLOBALS['BE_USER'] so DataHandler-based
 * endpoints run with real permissions. Select it per API definition via
 * "backendbeareropaquetokenprovider" in security.authProviders.
 */
class BackendBearerOpaqueTokenProvider extends BearerOpaqueTokenProvider {
	public function __construct(
		TokenRepository $tokenRepository,
		ExtensionConfiguration $extensionConfiguration,
		protected LanguageServiceFactory $languageServiceFactory
	) {
		parent::__construct($tokenRepository, $extensionConfiguration);
	}

	/**
	 * @param ServerRequestInterface $request
	 * @param string $apiId
	 * @param string|null $tenantId
	 * @param array<int|string, mixed> $activeProviders
	 * @return AuthContext|null
	 * @throws Exception
	 */
	public function authenticate(
		ServerRequestInterface $request,
		string $apiId,
		?string $tenantId,
		array $activeProviders = []
	): ?AuthContext {
		$tenantId ??= '';
		$token = $this->extractToken($request);
		if ($token === '') {
			return NULL;
		}

		/** @var TenantContext|null $tenantContext */
		$tenantContext = $request->getAttribute('api.tenant');
		$tokenRecord = $this->findMatchingToken($token, $apiId, $tenantId, $tenantContext?->getSiteRootPageId());
		if ($tokenRecord === NULL) {
			return NULL;
		}

		$expiresAt = $this->intValue($tokenRecord, 'expires_at');
		if ($expiresAt > 0 && $expiresAt < time()) {
			return NULL;
		}

		$backendUserUid = $this->intValue($tokenRecord, 'be_user_uid');
		if ($backendUserUid <= 0 || !$this->initializeBackendUser($backendUserUid, $request)) {
			return NULL;
		}

		$tokenUid = $this->intValue($tokenRecord, 'uid');
		$this->tokenRepository->updateLastUsed($tokenUid);

		$scopes = $tokenRecord['scopes'] ?? '';

		return new AuthContext(
			apiId: $apiId,
			tenantId: $tenantId,
			tokenUid: $tokenUid,
			scopes: $this->decodeScopes(\is_string($scopes) ? $scopes : ''),
			userId: $backendUserUid
		);
	}

	/**
	 * Boots the backend user referenced by the token into $GLOBALS['BE_USER'].
	 *
	 * @param int $backendUserUid
	 * @param ServerRequestInterface $request
	 * @return bool
	 */
	protected function initializeBackendUser(int $backendUserUid, ServerRequestInterface $request): bool {
		/** @var BackendUserAuthentication $backendUser */
		$backendUser = GeneralUtility::makeInstance(BackendUserAuthentication::class);
		$backendUser->setBeUserByUid($backendUserUid);
		if (empty($backendUser->user)) {
			return FALSE;
		}

		$backendUser->fetchGroupData();
		$this->applyRequestedWorkspace($backendUser, $request);
		$GLOBALS['BE_USER'] = $backendUser;
		if (!(($GLOBALS['LANG'] ?? NULL) instanceof LanguageService)) {
			$GLOBALS['LANG'] = $this->languageServiceFactory->create('default');
		}

		return TRUE;
	}

	/**
	 * @param BackendUserAuthentication $backendUser
	 * @param ServerRequestInterface $request
	 */
	protected function applyRequestedWorkspace(
		BackendUserAuthentication $backendUser,
		ServerRequestInterface $request
	): void {
		$queryParams = $request->getQueryParams();
		$workspaceHeader = $request->getHeaderLine('X-TYPO3-Workspace');
		$workspaceParam = $queryParams['workspace'] ?? NULL;
		$workspace = NULL;
		if ($workspaceHeader !== '') {
			$workspace = (int) $workspaceHeader;
		} elseif (is_numeric($workspaceParam)) {
			$workspace = (int) $workspaceParam;
		}

		if ($workspace !== NULL) {
			$backendUser->setTemporaryWorkspace($workspace);
		}
	}

	/**
	 * @param string $scopes
	 * @return array<int, string>
	 */
	protected function decodeScopes(string $scopes): array {
		if ($scopes === '') {
			return [];
		}

		try {
			$decoded = json_decode($scopes, TRUE, 512, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			return [];
		}

		return \is_array($decoded) ? array_values(array_filter($decoded, is_string(...))) : [];
	}

	/**
	 * @param array<mixed> $record
	 * @param string $key
	 * @return int
	 */
	protected function intValue(array $record, string $key): int {
		$value = $record[$key] ?? 0;
		return is_numeric($value) ? (int) $value : 0;
	}
}
