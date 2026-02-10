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

namespace SGalinski\SgApiCore\Service;

use TYPO3\CMS\Core\SingletonInterface;

/**
 * Registry for API configurations
 */
class ApiRegistry implements SingletonInterface {
	/**
	 * @var array
	 */
	protected array $apis = [];

	/**
	 * Registers an API
	 *
	 * @param string $apiId Unique identifier for the API
	 * @param array $versions Supported versions (e.g., ['1', '2'])
	 * @param array $security Security configuration. The key 'authMode' should be a string ('public', 'token' or 'user').
	 * @param string|null $basePath Optional base path override
	 * @param array $options Additional options
	 */
	public function registerApi(
		string $apiId,
		array $versions,
		array $security = [],
		?string $basePath = NULL,
		array $options = []
	): void {
		$rateLimit = $options['rateLimit'] ?? $security['rateLimit'] ?? NULL;

		$this->apis[$apiId] = [
			'versions' => $versions,
			'security' => $security,
			'basePath' => $basePath,
			'rateLimit' => $rateLimit
		];
	}

	/**
	 * Returns the security configuration for a given API and version
	 *
	 * @param string $apiId
	 * @param string $version
	 * @return array
	 */
	public function getSecurityConfig(string $apiId, string $version): array {
		$api = $this->getApi($apiId);
		if ($api === NULL) {
			return [];
		}

		$security = $api['security'] ?? [];
		// Version-specific security if defined
		return $security['versions'][$version] ?? $security;
	}

	/**
	 * Returns the configuration for the given API ID
	 *
	 * @param string $apiId
	 * @return array|null
	 */
	public function getApi(string $apiId): ?array {
		return $this->apis[$apiId] ?? NULL;
	}

	/**
	 * Returns the rate limit configuration for a given API and version
	 *
	 * @param string $apiId
	 * @param string $version
	 * @return array|null
	 */
	public function getRateLimitConfig(string $apiId, string $version): ?array {
		$api = $this->getApi($apiId);
		if ($api === NULL) {
			return NULL;
		}

		$rateLimit = $api['rateLimit'] ?? NULL;
		if (!is_array($rateLimit)) {
			return NULL;
		}

		if (isset($rateLimit['versions']) && is_array($rateLimit['versions'])) {
			return $rateLimit['versions'][$version] ?? $rateLimit;
		}

		return $rateLimit;
	}

	/**
	 * Returns all registered APIs
	 *
	 * @return array
	 */
	public function getApis(): array {
		return $this->apis;
	}

	/**
	 * Checks if an API is registered
	 *
	 * @param string $apiId
	 * @return bool
	 */
	public function hasApi(string $apiId): bool {
		return isset($this->apis[$apiId]);
	}
}
