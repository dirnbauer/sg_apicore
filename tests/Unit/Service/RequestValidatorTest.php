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
use SGalinski\SgApiCore\Attribute\ApiQueryParam;
use SGalinski\SgApiCore\Service\RequestValidator;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

class RequestValidatorTest extends UnitTestCase {
	protected RequestValidator $validator;

	protected function setUp(): void {
		parent::setUp();
		$this->validator = new RequestValidator();
	}

	/**
	 * @test
	 */
	public function testValidateDetectsMissingRequiredFields(): void {
		$request = $this->createStub(ServerRequestInterface::class);
		$request->method('getQueryParams')->willReturn([]);
		$request->method('getParsedBody')->willReturn([]);

		$endpoint = [
			'pathParams' => [],
			'queryParams' => [
				new ApiQueryParam(name: 'test', required: TRUE)
			],
			'bodyParams' => []
		];

		$errors = $this->validator->validate($request, $endpoint, []);
		$this->assertIsArray($errors);
		$this->assertEquals('test', $errors[0]['field']);
		$this->assertEquals('This field is required', $errors[0]['message']);
	}

	/**
	 * @test
	 */
	public function testValidateHandlesRequiredIfCondition(): void {
		$request = $this->createStub(ServerRequestInterface::class);
		$request->method('getQueryParams')->willReturn(['type' => 'special']);
		$request->method('getParsedBody')->willReturn([]);

		$endpoint = [
			'pathParams' => [],
			'queryParams' => [
				new ApiQueryParam(name: 'type'),
				new ApiQueryParam(name: 'extra', requiredIf: 'type=special')
			],
			'bodyParams' => []
		];

		// Case 1: type=special, extra is missing
		$errors = $this->validator->validate($request, $endpoint, []);
		$this->assertIsArray($errors);
		$this->assertEquals('extra', $errors[0]['field']);

		// Case 2: type=other, extra is missing (should be valid)
		$request2 = $this->createStub(ServerRequestInterface::class);
		$request2->method('getQueryParams')->willReturn(['type' => 'other']);
		$errors2 = $this->validator->validate($request2, $endpoint, []);
		$this->assertNull($errors2);
	}

	/**
	 * @test
	 */
	public function testValidateHandlesNumericRange(): void {
		$request = $this->createStub(ServerRequestInterface::class);
		$request->method('getQueryParams')->willReturn(['age' => 15]);

		$endpoint = [
			'pathParams' => [],
			'queryParams' => [
				new ApiQueryParam(name: 'age', type: 'integer', min: 18, max: 99)
			],
			'bodyParams' => []
		];

		$errors = $this->validator->validate($request, $endpoint, []);
		$this->assertIsArray($errors);
		$this->assertEquals('Value must be at least 18', $errors[0]['message']);

		$request2 = $this->createStub(ServerRequestInterface::class);
		$request2->method('getQueryParams')->willReturn(['age' => 100]);
		$errors2 = $this->validator->validate($request2, $endpoint, []);
		$this->assertEquals('Value must be at most 99', $errors2[0]['message']);
	}

	/**
	 * @test
	 */
	public function testValidateHandlesStringLength(): void {
		$request = $this->createStub(ServerRequestInterface::class);
		$request->method('getParsedBody')->willReturn(['username' => 'abc']);

		$endpoint = [
			'pathParams' => [],
			'queryParams' => [],
			'bodyParams' => [
				new ApiBodyParam(name: 'username', type: 'string', minLength: 5, maxLength: 10)
			]
		];

		$errors = $this->validator->validate($request, $endpoint, []);
		$this->assertIsArray($errors);
		$this->assertEquals('Value length must be at least 5', $errors[0]['message']);

		$request2 = $this->createStub(ServerRequestInterface::class);
		$request2->method('getParsedBody')->willReturn(['username' => 'verylongusername']);
		$errors2 = $this->validator->validate($request2, $endpoint, []);
		$this->assertEquals('Value length must be at most 10', $errors2[0]['message']);
	}

	/**
	 * @test
	 */
	public function testValidateHandlesRegexPattern(): void {
		$request = $this->createStub(ServerRequestInterface::class);
		$request->method('getQueryParams')->willReturn(['code' => '123']);

		$endpoint = [
			'pathParams' => [],
			'queryParams' => [
				new ApiQueryParam(name: 'code', pattern: '/^[A-Z]{3}$/')
			],
			'bodyParams' => []
		];

		$errors = $this->validator->validate($request, $endpoint, []);
		$this->assertIsArray($errors);
		$this->assertStringContainsString('does not match the required pattern', $errors[0]['message']);
	}
}
