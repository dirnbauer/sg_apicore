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

use TYPO3\CMS\Core\SingletonInterface;

/**
 * Service for JWT handling
 */
class JwtService implements SingletonInterface {
	/**
	 * @var string
	 */
	protected string $privateKey;

	public function __construct() {
		$this->privateKey = $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] ?? '';
		if (strlen($this->privateKey) < 32) {
			throw new \RuntimeException('Insecure or missing encryptionKey in TYPO3 configuration. A key with at least 32 characters is required.');
		}
	}

	/**
	 * Encodes a payload into a JWT
	 *
	 * @param array $payload
	 * @param string $algo
	 * @return string
	 * @throws \JsonException
	 */
	public function encode(array $payload, string $algo = 'HS256'): string {
		$header = ['typ' => 'JWT', 'alg' => $algo];

		$segments = [];
		$segments[] = $this->urlsafeB64Encode(json_encode($header, JSON_THROW_ON_ERROR));
		$segments[] = $this->urlsafeB64Encode(json_encode($payload, JSON_THROW_ON_ERROR));
		$signingInput = implode('.', $segments);

		$signature = $this->sign($signingInput, $this->privateKey, $algo);
		$segments[] = $this->urlsafeB64Encode($signature);

		return implode('.', $segments);
	}

	/**
	 * Decodes and verifies a JWT
	 *
	 * @param string $jwt
	 * @param array $expectedClaims (e.g. ['tenantId' => '...', 'apiId' => '...'])
	 * @return array|null
	 * @throws \JsonException
	 */
	public function decode(string $jwt, array $expectedClaims = []): ?array {
		$tokenSegments = explode('.', $jwt);
		if (count($tokenSegments) !== 3) {
			return NULL;
		}

		[$jwtHeaderEncoded, $jwtPayloadEncoded, $jwtSignatureEncoded] = $tokenSegments;

		$headerJson = $this->urlsafeB64Decode($jwtHeaderEncoded);
		$payloadJson = $this->urlsafeB64Decode($jwtPayloadEncoded);
		$signature = $this->urlsafeB64Decode($jwtSignatureEncoded);

		$header = json_decode($headerJson, TRUE, 512, JSON_THROW_ON_ERROR);
		$payload = json_decode($payloadJson, TRUE, 512, JSON_THROW_ON_ERROR);

		if (!$header || !$payload || empty($header['alg'])) {
			return NULL;
		}

		// Whitelist algorithms
		if (!in_array($header['alg'], ['HS256', 'HS384', 'HS512'], TRUE)) {
			return NULL;
		}

		// Use hash_equals for signature comparison
		if (!hash_equals(
			$signature,
			$this->sign("$jwtHeaderEncoded.$jwtPayloadEncoded", $this->privateKey, $header['alg'])
		)) {
			return NULL;
		}

		// Verify exp claim
		if (!isset($payload['exp']) || $payload['exp'] < time()) {
			return NULL;
		}

		// Verify jti claim
		if (!isset($payload['jti'])) {
			return NULL;
		}

		// Verify expected claims
		foreach ($expectedClaims as $key => $value) {
			if (!isset($payload[$key]) || $payload[$key] !== $value) {
				return NULL;
			}
		}

		return $payload;
	}

	/**
	 * Signs a message
	 *
	 * @param string $message
	 * @param string $key
	 * @param string $method
	 * @return string
	 */
	protected function sign(string $message, string $key, string $method = 'HS256'): string {
		$methods = [
			'HS256' => 'sha256',
			'HS384' => 'sha384',
			'HS512' => 'sha512',
		];

		if (empty($methods[$method])) {
			throw new \InvalidArgumentException('Algorithm not supported');
		}

		return hash_hmac($methods[$method], $message, $key, TRUE);
	}

	/**
	 * URL-safe Base64 Decode
	 *
	 * @param string $input
	 * @return string
	 */
	protected function urlsafeB64Decode(string $input): string {
		$remainder = strlen($input) % 4;
		if ($remainder) {
			$input .= str_repeat('=', 4 - $remainder);
		}
		return base64_decode(strtr($input, '-_', '+/'));
	}

	/**
	 * URL-safe Base64 Encode
	 *
	 * @param string $input
	 * @return string
	 */
	protected function urlsafeB64Encode(string $input): string {
		return str_replace('=', '', strtr(base64_encode($input), '+/', '-_'));
	}
}
