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
 * Attribute to configure logging for a specific API endpoint
 */
#[Attribute(Attribute::TARGET_METHOD)]
class ApiLogging {
	/**
	 * @param bool|null $enableLogging Whether logging is enabled for this endpoint (overrides global setting if not NULL)
	 * @param bool|null $logHeaders Whether headers should be logged
	 * @param bool|null $logBody Whether a request body should be logged
	 * @param bool|null $logResponse Whether a response body should be logged
	 */
	public function __construct(
		public ?bool $enableLogging = NULL,
		public ?bool $logHeaders = NULL,
		public ?bool $logBody = NULL,
		public ?bool $logResponse = NULL
	) {
	}
}
