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

/**
 * Allows extensions to filter discovered API endpoints before routing and documentation.
 */
interface EndpointFilterInterface {
	/**
	 * @param array<int, array<string, mixed>> $endpoints
	 * @return array<int, array<string, mixed>>
	 */
	public function filterEndpoints(array $endpoints): array;
}
