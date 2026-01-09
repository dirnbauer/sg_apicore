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
			if ($error = $this->validateValue($param, $value, TRUE)) {
				$errors[] = $error;
			}
		}

		// 2. Validate Query Parameters
		$queryParams = $request->getQueryParams();
		foreach ($endpoint['queryParams'] ?? [] as $param) {
			/** @var ApiQueryParam $param */
			$value = $queryParams[$param->name] ?? NULL;
			$required = $param->required;
			if ($param->requiredIf && $this->checkCondition($param->requiredIf, $queryParams)) {
				$required = TRUE;
			}

			if ($error = $this->validateValue($param, $value, $required)) {
				$errors[] = $error;
			}
		}

		// 3. Validate Body Parameters (JSON)
		if (!empty($endpoint['bodyParams'])) {
			$body = $request->getParsedBody();

			// Legacy parameter mapping (user -> username, pass -> password)
			if ($request->getAttribute('api.isLegacy') && is_array($body)) {
				if (isset($body['user']) && !isset($body['username'])) {
					$body['username'] = $body['user'];
				}
				if (isset($body['pass']) && !isset($body['password'])) {
					$body['password'] = $body['pass'];
				}
			}

			if (!is_array($body) && $request->getMethod() !== 'GET') {
				// If body params are expected but the body is not an array, check if any are required
				foreach ($endpoint['bodyParams'] as $param) {
					$required = $param->required;
					if ($param->requiredIf && $this->checkCondition($param->requiredIf, [])) {
						$required = TRUE;
					}

					if ($required) {
						$errors[] = [
							'field' => 'body',
							'message' => 'Request body must be a valid JSON object'
						];
						break;
					}
				}
			} else {
				$body = is_array($body) ? $body : [];
				foreach ($endpoint['bodyParams'] as $param) {
					/** @var ApiBodyParam $param */
					$value = $body[$param->name] ?? NULL;
					$required = $param->required;
					if ($param->requiredIf && $this->checkCondition($param->requiredIf, $body)) {
						$required = TRUE;
					}

					if ($error = $this->validateValue($param, $value, $required)) {
						$errors[] = $error;
					}
				}
			}
		}

		return count($errors) > 0 ? $errors : NULL;
	}

	/**
	 * Checks if a condition is met
	 *
	 * @param string $condition
	 * @param array $values
	 * @return bool
	 */
	protected function checkCondition(string $condition, array $values): bool {
		if (str_contains($condition, '=')) {
			[$field, $expectedValue] = explode('=', $condition, 2);
			$field = trim($field);
			$expectedValue = trim($expectedValue);
			$actualValue = $values[$field] ?? NULL;

			// Handle boolean strings
			if ($expectedValue === 'true') {
				return (bool) $actualValue === TRUE;
			}
			if ($expectedValue === 'false') {
				return (bool) $actualValue === FALSE;
			}

			return (string) $actualValue === $expectedValue;
		}

		return !empty($values[trim($condition)]);
	}

	/**
	 * Validates a single value
	 *
	 * @param ApiPathParam|ApiQueryParam|ApiBodyParam $param
	 * @param mixed $value
	 * @param bool $required
	 * @return array|null
	 */
	protected function validateValue(
		object $param,
		mixed $value,
		bool $required
	): ?array {
		$name = $param->name;
		$type = $param->type;
		$pattern = $param->pattern;

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
				if (property_exists($param, 'min') && $param->min !== NULL && (float) $value < $param->min) {
					return [
						'field' => $name,
						'message' => 'Value must be at least ' . $param->min
					];
				}
				if (property_exists($param, 'max') && $param->max !== NULL && (float) $value > $param->max) {
					return [
						'field' => $name,
						'message' => 'Value must be at most ' . $param->max
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
				if (property_exists($param, 'min') && $param->min !== NULL && (float) $value < $param->min) {
					return [
						'field' => $name,
						'message' => 'Value must be at least ' . $param->min
					];
				}
				if (property_exists($param, 'max') && $param->max !== NULL && (float) $value > $param->max) {
					return [
						'field' => $name,
						'message' => 'Value must be at most ' . $param->max
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
			case 'string':
				if (property_exists($param, 'minLength') && $param->minLength !== NULL && strlen((string) $value) < $param->minLength) {
					return [
						'field' => $name,
						'message' => 'Value length must be at least ' . $param->minLength
					];
				}
				if (property_exists($param, 'maxLength') && $param->maxLength !== NULL && strlen((string) $value) > $param->maxLength) {
					return [
						'field' => $name,
						'message' => 'Value length must be at most ' . $param->maxLength
					];
				}
				break;
		}

		// Pattern Validation
		if ($pattern !== NULL && $pattern !== '') {
			$regex = $pattern;
			if (!str_starts_with($regex, '/') || !str_ends_with($regex, '/')) {
				$regex = '/' . str_replace('/', '\/', $regex) . '/';
			}

			if (!preg_match($regex, (string) $value)) {
				return [
					'field' => $name,
					'message' => 'Value does not match the required pattern: ' . $pattern
				];
			}
		}

		return NULL;
	}
}
