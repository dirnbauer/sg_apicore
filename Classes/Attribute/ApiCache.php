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

namespace SGalinski\SgApiCore\Attribute;

use Attribute;

/**
 * Attribute to define API caching behavior
 */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class ApiCache {
	/**
	 * @param bool $enabled Whether caching is enabled (Default: TRUE)
	 * @param int $lifetime The cache lifetime in seconds (0 = default)
	 * @param array $tags Additional cache tags
	 * @param bool $useUserGroups Whether to vary the cache by user groups (Default: TRUE)
	 * @param bool $useLanguage Whether to vary the cache by language (Default: TRUE)
	 * @param array $additionalVary Additional query parameters or headers to vary by
	 */
	public function __construct(
		public bool $enabled = TRUE,
		public int $lifetime = 0,
		public array $tags = [],
		public bool $useUserGroups = TRUE,
		public bool $useLanguage = TRUE,
		public array $additionalVary = []
	) {
	}

	public static function __set_state(array $properties): self {
		return new self(
			$properties['enabled'] ?? TRUE,
			$properties['lifetime'] ?? 0,
			$properties['tags'] ?? [],
			$properties['useUserGroups'] ?? TRUE,
			$properties['useLanguage'] ?? TRUE,
			$properties['additionalVary'] ?? []
		);
	}
}
