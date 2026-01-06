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

use Psr\Http\Message\ResponseInterface;
use SGalinski\SgApiCore\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\SingletonInterface;

/**
 * Service for creating standardized API responses
 */
class ResponseService implements SingletonInterface {
	/**
	 * @var ExtensionConfiguration
	 */
	protected ExtensionConfiguration $extensionConfiguration;

	/**
	 * @param ExtensionConfiguration $extensionConfiguration
	 */
	public function __construct(ExtensionConfiguration $extensionConfiguration) {
		$this->extensionConfiguration = $extensionConfiguration;
	}

	/**
	 * Creates a successful JSON response, optionally wrapped in a data envelope
	 *
	 * @param mixed $data The data to return
	 * @param array $meta Optional metadata
	 * @param int $status HTTP status code
	 * @return ResponseInterface
	 */
	public function createSuccessResponse(mixed $data, array $meta = [], int $status = 200): ResponseInterface {
		if ($this->extensionConfiguration->isResponseEnvelopeEnabled()) {
			$response = [
				'data' => $data
			];
			if (count($meta) > 0) {
				$response['meta'] = $meta;
			}
		} else {
			$response = $data;
		}

		return new JsonResponse($response, $status);
	}

	/**
	 * Creates a Problem JSON error response (RFC 7807)
	 *
	 * @param string $title
	 * @param string $detail
	 * @param int $status
	 * @param string $type
	 * @param array $additionalData
	 * @return ResponseInterface
	 */
	public function createErrorResponse(
		string $title,
		string $detail,
		int $status,
		string $type = 'about:blank',
		array $additionalData = []
	): ResponseInterface {
		$response = [
			'title' => $title,
			'detail' => $detail,
			'status' => $status,
			'type' => $type
		];

		if (count($additionalData) > 0) {
			$response = array_merge($response, $additionalData);
		}

		return new JsonResponse($response, $status, ['Content-Type' => 'application/problem+json']);
	}
}
