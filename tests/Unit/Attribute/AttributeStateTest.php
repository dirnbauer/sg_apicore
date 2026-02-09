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

namespace SGalinski\SgApiCore\Tests\Unit\Attribute;

use SGalinski\SgApiCore\Attribute\ApiBodyParam;
use SGalinski\SgApiCore\Attribute\ApiResponse;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Test case for attribute state restoration
 */
class AttributeStateTest extends UnitTestCase {
	public function testApiBodyParamRestoresFromVarExport(): void {
		$param = new ApiBodyParam(name: 'username', type: 'string', required: TRUE, description: 'User name');
		$export = var_export($param, TRUE);
		$restored = eval('return ' . $export . ';');

		$this->assertInstanceOf(ApiBodyParam::class, $restored);
		$this->assertSame('username', $restored->name);
		$this->assertSame('string', $restored->type);
		$this->assertTrue($restored->required);
	}

	public function testApiResponseRestoresFromVarExport(): void {
		$response = new ApiResponse(status: 200, description: 'OK', schema: 'object');
		$export = var_export($response, TRUE);
		$restored = eval('return ' . $export . ';');

		$this->assertInstanceOf(ApiResponse::class, $restored);
		$this->assertSame(200, $restored->status);
		$this->assertSame('OK', $restored->description);
		$this->assertSame('object', $restored->schema);
	}
}
