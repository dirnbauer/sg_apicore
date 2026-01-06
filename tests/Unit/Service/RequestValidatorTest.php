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

namespace SGalinski\SgApiCore\Tests\Unit\Service;

use Psr\Http\Message\ServerRequestInterface;
use SGalinski\SgApiCore\Attribute\ApiBodyParam;
use SGalinski\SgApiCore\Attribute\ApiPathParam;
use SGalinski\SgApiCore\Attribute\ApiQueryParam;
use SGalinski\SgApiCore\Service\RequestValidator;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Test case for RequestValidator
 */
class RequestValidatorTest extends UnitTestCase {
	protected RequestValidator $validator;

	protected function setUp(): void {
		parent::setUp();
		$this->validator = new RequestValidator();
	}

	public function testValidateQueryParametersSuccessful(): void {
		$request = $this->createStub(ServerRequestInterface::class);
		$request->method('getQueryParams')->willReturn(['code' => 'ABC', 'page' => '1']);

		$endpoint = [
			'queryParams' => [
				new ApiQueryParam(name: 'code', type: 'string', required: TRUE, pattern: '/^[A-Z]{3}$/'),
				new ApiQueryParam(name: 'page', type: 'integer')
			]
		];

		$errors = $this->validator->validate($request, $endpoint, []);
		$this->assertNull($errors);
	}

	public function testValidateQueryParametersFailsOnMissingRequired(): void {
		$request = $this->createStub(ServerRequestInterface::class);
		$request->method('getQueryParams')->willReturn([]);

		$endpoint = [
			'queryParams' => [
				new ApiQueryParam(name: 'code', type: 'string', required: TRUE)
			]
		];

		$errors = $this->validator->validate($request, $endpoint, []);
		$this->assertIsArray($errors);
		$this->assertCount(1, $errors);
		$this->assertEquals('code', $errors[0]['field']);
		$this->assertStringContainsString('required', $errors[0]['message']);
	}

	public function testValidateQueryParametersFailsOnInvalidPattern(): void {
		$request = $this->createStub(ServerRequestInterface::class);
		$request->method('getQueryParams')->willReturn(['code' => 'abc']);

		$endpoint = [
			'queryParams' => [
				new ApiQueryParam(name: 'code', type: 'string', pattern: '/^[A-Z]{3}$/')
			]
		];

		$errors = $this->validator->validate($request, $endpoint, []);
		$this->assertIsArray($errors);
		$this->assertCount(1, $errors);
		$this->assertStringContainsString('pattern', $errors[0]['message']);
	}

	public function testValidateQueryParametersFailsOnInvalidType(): void {
		$request = $this->createStub(ServerRequestInterface::class);
		$request->method('getQueryParams')->willReturn(['page' => 'not-a-number']);

		$endpoint = [
			'queryParams' => [
				new ApiQueryParam(name: 'page', type: 'integer')
			]
		];

		$errors = $this->validator->validate($request, $endpoint, []);
		$this->assertIsArray($errors);
		$this->assertCount(1, $errors);
		$this->assertStringContainsString('integer', $errors[0]['message']);
	}

	public function testValidatePathParametersSuccessful(): void {
		$request = $this->createStub(ServerRequestInterface::class);
		$request->method('getQueryParams')->willReturn([]);

		$endpoint = [
			'pathParams' => [
				new ApiPathParam(name: 'id', type: 'integer')
			]
		];

		$errors = $this->validator->validate($request, $endpoint, ['id' => '123']);
		$this->assertNull($errors);
	}

	public function testValidateBodyParametersSuccessful(): void {
		$request = $this->createStub(ServerRequestInterface::class);
		$request->method('getParsedBody')->willReturn(['username' => 'testuser']);
		$request->method('getMethod')->willReturn('POST');

		$endpoint = [
			'bodyParams' => [
				new ApiBodyParam(name: 'username', type: 'string', required: TRUE)
			]
		];

		$errors = $this->validator->validate($request, $endpoint, []);
		$this->assertNull($errors);
	}
}
