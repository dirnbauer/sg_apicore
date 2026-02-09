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

namespace SGalinski\SgApiCore\Attribute;

use Attribute;

/**
 * Attribute to enable legacy compatibility mode for an endpoint
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
class ApiLegacyMode {
	/**
	 * @param string $source The legacy system to emulate (e.g., 'sg_rest')
	 * @param bool $wrapData Whether to always wrap the response in a 'data' property
	 * @param bool $legacyErrorFormat Whether to use the legacy error format instead of RFC 7807
	 */
	public function __construct(
		public string $source = 'sg_rest',
		public bool $wrapData = TRUE,
		public bool $legacyErrorFormat = TRUE
	) {
	}
}
