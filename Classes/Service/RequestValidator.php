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
use SGalinski\SgApiCore\Attribute\ApiBodyParam;
use SGalinski\SgApiCore\Attribute\ApiPathParam;
use SGalinski\SgApiCore\Attribute\ApiQueryParam;
use TYPO3\CMS\Core\SingletonInterface;

/**
 * Service for validating API requests based on endpoint metadata
 */
class RequestValidator implements SingletonInterface {
	/**
	 * Validates the request against the endpoint metadata
	 *
	 * @param ServerRequestInterface $request
	 * @param array $endpoint
	 * @param array $pathParams
	 * @return array|null Null if valid, otherwise an array of errors
	 */
	public function validate(ServerRequestInterface $request, array $endpoint, array $pathParams): ?array {
		$errors = [];

		// 1. Validate Path Parameters
		foreach ($endpoint['pathParams'] ?? [] as $param) {
			/** @var ApiPathParam $param */
			$value = $pathParams[$param->name] ?? NULL;
			if ($error = $this->validateValue($param->name, $value, $param->type, TRUE, $param->pattern)) {
				$errors[] = $error;
			}
		}

		// 2. Validate Query Parameters
		$queryParams = $request->getQueryParams();
		foreach ($endpoint['queryParams'] ?? [] as $param) {
			/** @var ApiQueryParam $param */
			$value = $queryParams[$param->name] ?? NULL;
			if ($error = $this->validateValue($param->name, $value, $param->type, $param->required, $param->pattern)) {
				$errors[] = $error;
			}
		}

		// 3. Validate Body Parameters (JSON)
		if (!empty($endpoint['bodyParams'])) {
			$body = $request->getParsedBody();
			if (!is_array($body) && $request->getMethod() !== 'GET') {
				// If body params are expected but the body is not an array, check if any are required
				foreach ($endpoint['bodyParams'] as $param) {
					if ($param->required) {
						$errors[] = [
							'field' => 'body',
							'message' => 'Request body must be a valid JSON object'
						];
						break;
					}
				}
			} else {
				foreach ($endpoint['bodyParams'] as $param) {
					/** @var ApiBodyParam $param */
					$value = $body[$param->name] ?? NULL;
					if ($error = $this->validateValue(
						$param->name,
						$value,
						$param->type,
						$param->required,
						$param->pattern
					)) {
						$errors[] = $error;
					}
				}
			}
		}

		return count($errors) > 0 ? $errors : NULL;
	}

	/**
	 * Validates a single value
	 *
	 * @param string $name
	 * @param mixed $value
	 * @param string $type
	 * @param bool $required
	 * @param string|null $pattern
	 * @return array|null
	 */
	protected function validateValue(
		string $name,
		mixed $value,
		string $type,
		bool $required,
		?string $pattern
	): ?array {
		if ($value === NULL || $value === '') {
			if ($required) {
				return [
					'field' => $name,
					'message' => 'This field is required'
				];
			}
			return NULL;
		}

		// Type Validation
		switch (strtolower($type)) {
			case 'int':
			case 'integer':
				if (!is_numeric($value)) {
					return [
						'field' => $name,
						'message' => 'Value must be an integer'
					];
				}
				break;
			case 'float':
			case 'double':
			case 'number':
				if (!is_numeric($value)) {
					return [
						'field' => $name,
						'message' => 'Value must be a number'
					];
				}
				break;
			case 'bool':
			case 'boolean':
				$boolValues = ['1', '0', 'true', 'false', TRUE, FALSE, 1, 0];
				if (!in_array($value, $boolValues, TRUE)) {
					return [
						'field' => $name,
						'message' => 'Value must be a boolean'
					];
				}
				break;
		}

		// Pattern Validation
		if ($pattern !== NULL && $pattern !== '' && !preg_match($pattern, (string) $value)) {
			return [
				'field' => $name,
				'message' => 'Value does not match the required pattern: ' . $pattern
			];
		}

		return NULL;
	}
}
