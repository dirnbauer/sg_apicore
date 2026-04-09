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
use Psr\Http\Message\UploadedFileInterface;
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
			if ($request->getAttribute('api.isLegacy') && \is_array($body)) {
				if (isset($body['user']) && !isset($body['username'])) {
					$body['username'] = $body['user'];
				}
				if (isset($body['pass']) && !isset($body['password'])) {
					$body['password'] = $body['pass'];
				}
			}

			if (!\is_array($body) && $request->getMethod() !== 'GET') {
				// If body params are expected but the body is not an array, check if any are required
				foreach ($endpoint['bodyParams'] as $param) {
					$required = $param->required;
					if ($param->requiredIf && $this->checkCondition($param->requiredIf, [])) {
						$required = TRUE;
					}

					if ($required) {
						$errors[] = [
							'field' => 'body',
							'message' => 'Request body must be a valid JSON object',
						];
						break;
					}
				}
			} else {
				$body = \is_array($body) ? $body : [];
				foreach ($endpoint['bodyParams'] as $param) {
					/** @var ApiBodyParam $param */
					$value = $body[$param->name] ?? NULL;
					if (strtolower($param->type) === 'file') {
						$value = $this->resolveValidUploadedFileValue($request, $param->name);
					}
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

		return \count($errors) > 0 ? $errors : NULL;
	}

	/**
	 * Resolve an uploaded file value for a body parameter and only accept successfully uploaded files.
	 *
	 * @param ServerRequestInterface $request
	 * @param string $parameterName
	 * @return UploadedFileInterface|array<int, UploadedFileInterface>|null
	 */
	protected function resolveValidUploadedFileValue(
		ServerRequestInterface $request,
		string $parameterName
	): UploadedFileInterface|array|NULL {
		$uploadedFiles = $request->getUploadedFiles();
		if (!\is_array($uploadedFiles) || !\array_key_exists($parameterName, $uploadedFiles)) {
			return NULL;
		}

		return $this->normalizeUploadedFileValue($uploadedFiles[$parameterName]);
	}

	/**
	 * @param mixed $value
	 * @return UploadedFileInterface|array<int, UploadedFileInterface>|null
	 */
	protected function normalizeUploadedFileValue(mixed $value): UploadedFileInterface|array|NULL {
		if ($value instanceof UploadedFileInterface) {
			return $value->getError() === UPLOAD_ERR_OK ? $value : NULL;
		}

		if (!\is_array($value)) {
			return NULL;
		}

		$normalized = [];
		foreach ($value as $item) {
			$normalizedItem = $this->normalizeUploadedFileValue($item);
			if ($normalizedItem instanceof UploadedFileInterface) {
				$normalized[] = $normalizedItem;
			} elseif (\is_array($normalizedItem)) {
				$normalized = array_merge($normalized, $normalizedItem);
			}
		}

		return $normalized !== [] ? $normalized : NULL;
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
	 * @param object $param
	 * @param mixed $value
	 * @param bool $required
	 * @return array|null
	 */
	protected function validateValue(object $param, mixed $value, bool $required): ?array {
		$name = $param->name;
		$type = $param->type;
		$pattern = $param->pattern;

		if ($value === NULL || $value === '') {
			if ($required) {
				return [
					'field' => $name,
					'message' => 'This field is required',
				];
			}
			return NULL;
		}

		// If the expected type is array, and we have an array, validate it as a whole.
		if (strtolower($type) === 'array') {
			if (!\is_array($value)) {
				// If we expect an array but get a scalar, we wrap it in an array for consistent processing
				$value = [$value];
			} else {
				// We have an array and expect an array.
				// Since Swagger-UI sends numeric values without quotes in arrays,
				// we validate each element of the array against a string type if it was an array[string]
				foreach ($value as $index => $subValue) {
					$subError = $this->validateValue(
						new (\get_class($param))(
							name: $name . '[' . $index . ']',
							type: 'string',
							required: $required,
							pattern: $pattern,
							min: property_exists($param, 'min') ? $param->min : NULL,
							max: property_exists($param, 'max') ? $param->max : NULL,
							minLength: property_exists($param, 'minLength') ? $param->minLength : NULL,
							maxLength: property_exists($param, 'maxLength') ? $param->maxLength : NULL
						),
						$subValue,
						$required
					);

					if ($subError) {
						return $subError;
					}
				}
				return NULL;
			}
		}

		// If it's an array, but we expect a scalar type, we iterate and validate each element.
		// This happens for query parameters like ?theme[]=1&theme[]=2 which TYPO3 parses as an array.
		if (\is_array($value)) {
			foreach ($value as $index => $subValue) {
				$subError = $this->validateValue(
					new (\get_class($param))(
						name: $name . '[' . $index . ']',
						type: strtolower($type) === 'array' ? 'string' : $type,
						required: $required,
						pattern: $pattern,
						min: property_exists($param, 'min') ? $param->min : NULL,
						max: property_exists($param, 'max') ? $param->max : NULL,
						minLength: property_exists($param, 'minLength') ? $param->minLength : NULL,
						maxLength: property_exists($param, 'maxLength') ? $param->maxLength : NULL
					),
					$subValue,
					$required
				);

				if ($subError) {
					return $subError;
				}
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
						'message' => 'Value must be an integer',
					];
				}
				if (property_exists($param, 'min') && $param->min !== NULL && (float) $value < $param->min) {
					return [
						'field' => $name,
						'message' => 'Value must be at least ' . $param->min,
					];
				}
				if (property_exists($param, 'max') && $param->max !== NULL && (float) $value > $param->max) {
					return [
						'field' => $name,
						'message' => 'Value must be at most ' . $param->max,
					];
				}
				break;
			case 'float':
			case 'double':
			case 'number':
				if (!is_numeric($value)) {
					return [
						'field' => $name,
						'message' => 'Value must be a number',
					];
				}
				if (property_exists($param, 'min') && $param->min !== NULL && (float) $value < $param->min) {
					return [
						'field' => $name,
						'message' => 'Value must be at least ' . $param->min,
					];
				}
				if (property_exists($param, 'max') && $param->max !== NULL && (float) $value > $param->max) {
					return [
						'field' => $name,
						'message' => 'Value must be at most ' . $param->max,
					];
				}
				break;
			case 'bool':
			case 'boolean':
				$boolValues = ['1', '0', 'true', 'false', TRUE, FALSE, 1, 0];
				if (!\in_array($value, $boolValues, TRUE)) {
					return [
						'field' => $name,
						'message' => 'Value must be a boolean',
					];
				}
				break;
			case 'string':
				if (!\is_scalar($value)) {
					return [
						'field' => $name,
						'message' => 'Value must be a string',
					];
				}

				$stringValue = (string) $value;
				if (property_exists($param, 'minLength') && $param->minLength !== NULL && \strlen(
					$stringValue
				) < $param->minLength) {
					return [
						'field' => $name,
						'message' => 'Value length must be at least ' . $param->minLength,
					];
				}
				if (property_exists($param, 'maxLength') && $param->maxLength !== NULL && \strlen(
					$stringValue
				) > $param->maxLength) {
					return [
						'field' => $name,
						'message' => 'Value length must be at most ' . $param->maxLength,
					];
				}
				break;
		}

		// Pattern Validation
		if ($pattern !== NULL && $pattern !== '' && !\is_array($value)) {
			$regex = $pattern;
			if (!str_starts_with($regex, '/') || !str_ends_with($regex, '/')) {
				$regex = '/' . str_replace('/', '\/', $regex) . '/';
			}

			if (!preg_match($regex, (string) $value)) {
				return [
					'field' => $name,
					'message' => 'Value does not match the required pattern: ' . $pattern,
				];
			}
		}

		return NULL;
	}
}
