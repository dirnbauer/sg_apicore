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

namespace SGalinski\SgApiCore\Service;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\SingletonInterface;

/**
 * Service for handling API pagination
 */
class PaginationService implements SingletonInterface {
	public const int DEFAULT_LIMIT = 10;
	public const int MAX_LIMIT = 100;

	/**
	 * Returns the pagination parameters from the request
	 *
	 * @param ServerRequestInterface $request
	 * @return array{offset: int, limit: int}
	 */
	public function getPaginationParams(ServerRequestInterface $request): array {
		$queryParams = $request->getQueryParams();
		$offset = max(0, (int) ($queryParams['offset'] ?? 0));
		$limit = (int) ($queryParams['limit'] ?? self::DEFAULT_LIMIT);

		if ($limit <= 0) {
			$limit = self::DEFAULT_LIMIT;
		}

		if ($limit > self::MAX_LIMIT) {
			$limit = self::MAX_LIMIT;
		}

		return [
			'offset' => $offset,
			'limit' => $limit
		];
	}

	/**
	 * Builds the pagination metadata array
	 *
	 * @param int $total Total number of items
	 * @param int $offset Current offset
	 * @param int $limit Current limit
	 * @return array
	 */
	public function buildPaginationMeta(int $total, int $offset, int $limit): array {
		return [
			'total' => $total,
			'offset' => $offset,
			'limit' => $limit,
			'count' => min($limit, max(0, $total - $offset))
		];
	}
}
