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

namespace SGalinski\SgApiCore\Configuration;

use Exception;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration as Typo3ExtensionConfiguration;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Configuration reader for the API core extension
 */
class ExtensionConfiguration implements SingletonInterface {
	/**
	 * @var array
	 */
	protected array $configuration = [];

	/**
	 * @var Typo3ExtensionConfiguration
	 */
	protected Typo3ExtensionConfiguration $typo3ExtensionConfiguration;

	/**
	 * ExtensionConfiguration constructor.
	 *
	 * @param Typo3ExtensionConfiguration|null $typo3ExtensionConfiguration
	 */
	public function __construct(?Typo3ExtensionConfiguration $typo3ExtensionConfiguration = NULL) {
		$this->typo3ExtensionConfiguration = $typo3ExtensionConfiguration ?? GeneralUtility::makeInstance(
			Typo3ExtensionConfiguration::class
		);
		try {
			$this->configuration = (array) $this->typo3ExtensionConfiguration->get('sg_apicore');
		} catch (Exception) {
			// Fallback to empty configuration
			$this->configuration = [];
		}
	}

	/**
	 * Returns the configuration value for the given key
	 *
	 * @param string $key
	 * @param mixed|null $default
	 * @return mixed
	 */
	public function get(string $key, mixed $default = NULL): mixed {
		return $this->configuration[$key] ?? $default;
	}

	/**
	 * Returns whether logging is enabled
	 *
	 * @return bool
	 */
	public function isLoggingEnabled(): bool {
		return (bool) $this->get('enableLogging', FALSE);
	}

	/**
	 * Returns whether request headers should be logged
	 *
	 * @return bool
	 */
	public function isLogHeadersEnabled(): bool {
		return (bool) $this->get('logHeaders', FALSE);
	}

	/**
	 * Returns whether the request body should be logged
	 *
	 * @return bool
	 */
	public function isLogBodyEnabled(): bool {
		return (bool) $this->get('logBody', FALSE);
	}

	/**
	 * Returns whether the response body should be logged
	 *
	 * @return bool
	 */
	public function isLogResponseEnabled(): bool {
		return (bool) $this->get('logResponse', FALSE);
	}

	/**
	 * Returns the list of keys to redact in logs
	 *
	 * @return array
	 */
	public function getRedactKeys(): array {
		$keys = $this->get(
			'redactKeys',
			'password,token,authorization,secret,access_token,refresh_token,authtoken,bearertoken,api_key,x-api-key,client_secret,cookie,set-cookie,stripe-signature'
		);
		return GeneralUtility::trimExplode(',', (string) $keys, TRUE);
	}

	/**
	 * Returns the max length for logged request/response bodies (0 = unlimited)
	 *
	 * @return int
	 */
	public function getLogBodyMaxLength(): int {
		return (int) $this->get('logBodyMaxLength', 4096);
	}

	/**
	 * Returns the token expiration time in seconds
	 *
	 * @return int
	 */
	public function getTokenExpirationTime(): int {
		return (int) $this->get('tokenExpirationTime', 86400);
	}

	/**
	 * Returns the JWT access token TTL in seconds
	 * If not set, falls back to tokenExpirationTime (backward compatibility)
	 */
	public function getJwtAccessTokenTtlSeconds(): int {
		return (int) $this->get('jwtAccessTokenTtlSeconds', $this->getTokenExpirationTime());
	}

	/**
	 * Returns the opaque (DB-backed) access token TTL in seconds
	 */
	public function getOpaqueAccessTokenTtlSeconds(): int {
		return (int) $this->get('opaqueAccessTokenTtlSeconds', 3600);
	}

	/**
	 * Returns the refresh token TTL in seconds
	 */
	public function getRefreshTokenTtlSeconds(): int {
		return (int) $this->get('refreshTokenTtlSeconds', 30 * 24 * 3600);
	}

	/**
	 * Returns the path prefix for the API
	 *
	 * @return string
	 */
	public function getApiPathPrefix(): string {
		$prefix = trim($this->get('apiPathPrefix', '/api/'));
		return '/' . trim($prefix, '/') . '/';
	}

	/**
	 * Returns the site tenant ID source (identifier|baseHost|rootPageId)
	 *
	 * @return string
	 */
	public function getSiteTenantIdSource(): string {
		return (string) $this->get('siteTenantIdSource', 'identifier');
	}

	/**
	 * Returns the HTTP status code to return when the tenant is missing
	 *
	 * @return int
	 */
	public function getOnMissingTenantStatusCode(): int {
		return (int) $this->get('onMissingTenant', 404);
	}

	/**
	 * Returns whether the response envelope (data: ...) is enabled
	 *
	 * @return bool
	 */
	public function isResponseEnvelopeEnabled(): bool {
		return (bool) $this->get('responseEnvelope', FALSE);
	}

	/**
	 * Returns whether caching is enabled
	 *
	 * @return bool
	 */
	public function isCacheEnabled(): bool {
		return (bool) $this->get('enableCaching', TRUE);
	}

	/**
	 * Returns whether demo APIs and resources should be activated
	 *
	 * @return bool
	 */
	public function isActivateDemoApis(): bool {
		return (bool) $this->get('activateDemoApis', TRUE);
	}

	/**
	 * Returns whether legacy support for sg_rest should be activated
	 *
	 * @return bool
	 */
	public function isActivateLegacySupport(): bool {
		return (bool) $this->get('activateLegacySupport', FALSE);
	}

	/**
	 * Returns whether rate limiting is enabled
	 *
	 * @return bool
	 */
	public function isRateLimitEnabled(): bool {
		return (bool) $this->get('rateLimitEnabled', TRUE);
	}

	/**
	 * Returns the default rate limit (requests per window)
	 *
	 * @return int
	 */
	public function getRateLimitDefaultLimit(): int {
		return (int) $this->get('rateLimitDefaultLimit', 60);
	}

	/**
	 * Returns the rate limit window size in seconds
	 *
	 * @return int
	 */
	public function getRateLimitWindowSeconds(): int {
		return (int) $this->get('rateLimitWindowSeconds', 60);
	}

	/**
	 * Returns the default rate limit burst size
	 *
	 * @return int
	 */
	public function getRateLimitDefaultBurst(): int {
		return (int) $this->get('rateLimitDefaultBurst', 0);
	}

	/**
	 * Returns the backend user uid for Auto-CRUD resource write operations
	 *
	 * @return int
	 */
	public function getApiResourceWriteBackendUserId(): int {
		return (int) $this->get('apiResourceWriteBackendUserId', 0);
	}

	/**
	 * Returns the workspace uid for Auto-CRUD resource write operations.
	 * A value below 0 keeps the current/default backend user workspace.
	 *
	 * @return int
	 */
	public function getApiResourceWriteWorkspaceId(): int {
		return (int) $this->get('apiResourceWriteWorkspaceId', -1);
	}

	/**
	 * Returns whether MCP support is enabled globally.
	 *
	 * @return bool
	 */
	public function isMcpEnabled(): bool {
		return (bool) $this->get('mcpEnabled', TRUE);
	}

	/**
	 * Returns API IDs for which MCP is globally disabled via extension config.
	 *
	 * @return list<string>
	 */
	public function getMcpDisabledApis(): array {
		$apiIds = $this->get('mcpDisabledApis', '');
		if (!\is_scalar($apiIds)) {
			return [];
		}
		return GeneralUtility::trimExplode(',', (string) $apiIds, TRUE);
	}

	/**
	 * Returns a global MCP denylist.
	 *
	 * Each entry can match a generated endpoint ID or tool name.
	 *
	 * @return list<string>
	 */
	public function getMcpDenylist(): array {
		$entries = $this->get('mcpDenylist', '');
		if (!\is_scalar($entries)) {
			return [];
		}
		return GeneralUtility::trimExplode(',', (string) $entries, TRUE);
	}
}
