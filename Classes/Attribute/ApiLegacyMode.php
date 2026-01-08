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
