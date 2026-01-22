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

namespace SGalinski\SgApiCore\Configuration;

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
	 * ExtensionConfiguration constructor.
	 *
	 * @param Typo3ExtensionConfiguration|null $extensionConfiguration
	 */
	public function __construct(?Typo3ExtensionConfiguration $extensionConfiguration = NULL) {
		$extensionConfiguration ??= GeneralUtility::makeInstance(Typo3ExtensionConfiguration::class);
		try {
			$this->configuration = $extensionConfiguration->get('sg_apicore');
		} catch (\Exception) {
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
		$keys = $this->get('redactKeys', 'password,token,authorization,secret,access_token,refresh_token');
		return GeneralUtility::trimExplode(',', (string) $keys, TRUE);
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
	 * Returns the backend user uid for Auto-CRUD resource write operations
	 *
	 * @return int
	 */
	public function getApiResourceWriteBackendUserId(): int {
		return (int) $this->get('apiResourceWriteBackendUserId', 0);
	}
}
