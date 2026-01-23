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
