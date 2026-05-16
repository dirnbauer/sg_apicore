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

namespace SGalinski\SgApiCore\Tests\Unit\Service;

use SGalinski\SgApiCore\Service\JwtService;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Test case for JwtService
 */
class JwtServiceTest extends UnitTestCase {
	private ?string $previousEncryptionKey = NULL;

	protected function setUp(): void {
		parent::setUp();
		$typo3ConfVars = $this->getTypo3ConfVars();
		$sysConfiguration = $this->getSysConfiguration($typo3ConfVars);
		$previousEncryptionKey = $sysConfiguration['encryptionKey'] ?? NULL;
		$this->previousEncryptionKey = \is_string($previousEncryptionKey) ? $previousEncryptionKey : NULL;
		$sysConfiguration['encryptionKey'] = str_repeat('a', 32);
		$typo3ConfVars['SYS'] = $sysConfiguration;
		$GLOBALS['TYPO3_CONF_VARS'] = $typo3ConfVars;
	}

	protected function tearDown(): void {
		$typo3ConfVars = $this->getTypo3ConfVars();
		$sysConfiguration = $this->getSysConfiguration($typo3ConfVars);
		if ($this->previousEncryptionKey === NULL) {
			unset($sysConfiguration['encryptionKey']);
		} else {
			$sysConfiguration['encryptionKey'] = $this->previousEncryptionKey;
		}
		$typo3ConfVars['SYS'] = $sysConfiguration;
		$GLOBALS['TYPO3_CONF_VARS'] = $typo3ConfVars;
		parent::tearDown();
	}

	/**
	 * @throws \JsonException
	 */
	public function testDecodeReturnsNullForMalformedBase64Segments(): void {
		$this->assertNull((new JwtService())->decode('!!!!.!!!!.!!!!'));
	}

	/**
	 * @throws \JsonException
	 */
	public function testDecodeReturnsPayloadForValidTokenAndExpectedClaims(): void {
		$service = new JwtService();
		$payload = [
			'exp' => time() + 3600,
			'jti' => 'test-jti',
			'apiId' => 'test-api',
			'tenantId' => 'default',
		];

		$token = $service->encode($payload);

		$this->assertSame($payload, $service->decode($token, ['apiId' => 'test-api', 'tenantId' => 'default']));
	}

	/**
	 * @return array<string,mixed>
	 */
	private function getTypo3ConfVars(): array {
		$typo3ConfVars = $GLOBALS['TYPO3_CONF_VARS'] ?? [];
		return \is_array($typo3ConfVars) ? $this->normalizeStringKeyArray($typo3ConfVars) : [];
	}

	/**
	 * @param array<string,mixed> $typo3ConfVars
	 * @return array<string,mixed>
	 */
	private function getSysConfiguration(array $typo3ConfVars): array {
		$sysConfiguration = $typo3ConfVars['SYS'] ?? [];
		return \is_array($sysConfiguration) ? $this->normalizeStringKeyArray($sysConfiguration) : [];
	}

	/**
	 * @param array<mixed> $values
	 * @return array<string,mixed>
	 */
	private function normalizeStringKeyArray(array $values): array {
		$stringKeyValues = [];
		foreach ($values as $key => $value) {
			if (\is_string($key)) {
				$stringKeyValues[$key] = $value;
			}
		}
		return $stringKeyValues;
	}
}
