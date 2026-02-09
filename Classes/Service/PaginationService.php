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

		$limit = (int) ($queryParams['perPage'] ?? $queryParams['limit'] ?? self::DEFAULT_LIMIT);
		if ($limit <= 0) {
			$limit = self::DEFAULT_LIMIT;
		}
		if ($limit > self::MAX_LIMIT) {
			$limit = self::MAX_LIMIT;
		}

		if (isset($queryParams['page'])) {
			$page = max(1, (int) $queryParams['page']);
			$offset = ($page - 1) * $limit;
		} else {
			$offset = max(0, (int) ($queryParams['offset'] ?? 0));
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
		$page = (int) floor($offset / $limit) + 1;
		$pages = (int) ceil($total / $limit);

		return [
			'total' => $total,
			'offset' => $offset,
			'limit' => $limit,
			'count' => min($limit, max(0, $total - $offset)),
			'page' => $page,
			'pages' => $pages
		];
	}
}
