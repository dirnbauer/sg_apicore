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

use SGalinski\SgApiCore\Service\SchemaRegistry;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Test case for SchemaRegistry
 */
class SchemaRegistryTest extends UnitTestCase {
	public function testRegisterAndGetSchema(): void {
		$registry = new SchemaRegistry();
		$schema = ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]];

		$registry->registerSchema('test_api', 'TestSchema', $schema);

		$this->assertTrue($registry->hasSchema('test_api', 'TestSchema'));
		$this->assertSame($schema, $registry->getSchema('test_api', 'TestSchema'));
		$this->assertArrayHasKey('TestSchema', $registry->getSchemas('test_api'));
		$this->assertSame($schema, $registry->getSchemas('test_api')['TestSchema']);
	}

	public function testGetNonExistentSchema(): void {
		$registry = new SchemaRegistry();
		$this->assertNull($registry->getSchema('test_api', 'NonExistent'));
	}
}
