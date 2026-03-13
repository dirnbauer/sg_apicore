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

namespace SGalinski\SgApiCore\Tests\Unit\Mapper;

use SGalinski\SgApiCore\Mapper\TcaMapper;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\FileRepository;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Extbase\Persistence\Generic\PersistenceManager;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Test case for TcaMapper
 */
class TcaMapperTest extends UnitTestCase {
	protected bool $resetSingletonInstances = TRUE;
	protected TcaMapper $mapper;

	protected function setUp(): void {
		parent::setUp();
		$persistenceManager = $this->createStub(PersistenceManager::class);
		$connectionPool = $this->createStub(ConnectionPool::class);
		$resourceFactory = $this->createStub(ResourceFactory::class);
		$fileRepository = $this->createStub(FileRepository::class);
		$contentObjectRenderer = $this->createStub(ContentObjectRenderer::class);

		$this->mapper = new TcaMapper(
			$persistenceManager,
			$connectionPool,
			$resourceFactory,
			$fileRepository,
			$contentObjectRenderer
		);

		$GLOBALS['TCA']['tx_test'] = [
			'columns' => [
				'title' => ['config' => ['type' => 'input']],
				'hidden_field' => ['config' => ['type' => 'input']],
				'number' => ['config' => ['type' => 'number']],
				'check' => ['config' => ['type' => 'check']],
			]
		];
	}

	protected function tearDown(): void {
		unset($GLOBALS['TCA']['tx_test']);
		parent::tearDown();
	}

	public function testMapRecordRespectsAllowedFields(): void {
		$record = [
			'uid' => 1,
			'title' => 'Test',
			'hidden_field' => 'Hidden'
		];

		$result = $this->mapper->mapRecord('tx_test', $record, ['uid', 'title']);

		$this->assertArrayHasKey('uid', $result);
		$this->assertArrayHasKey('title', $result);
		$this->assertArrayNotHasKey('hidden_field', $result);
	}

	public function testMapRecordRespectsExcludedFields(): void {
		$record = [
			'uid' => 1,
			'title' => 'Test',
			'hidden_field' => 'Hidden'
		];

		$result = $this->mapper->mapRecord('tx_test', $record, [], ['hidden_field']);

		$this->assertArrayHasKey('uid', $result);
		$this->assertArrayHasKey('title', $result);
		$this->assertArrayNotHasKey('hidden_field', $result);
	}

	public function testTransformValueHandlesTypes(): void {
		$reflection = new \ReflectionClass(TcaMapper::class);
		$method = $reflection->getMethod('transformValue');
		$method->setAccessible(TRUE);

		// Number
		$result = $method->invoke($this->mapper, '123', ['type' => 'number'], 0, 'tx_test', 1, 'number', []);
		$this->assertSame(123, $result);

		// Checkbox (bool)
		$result = $method->invoke($this->mapper, '1', ['type' => 'check'], 0, 'tx_test', 1, 'check', []);
		$this->assertTrue($result);

		$result = $method->invoke($this->mapper, '0', ['type' => 'check'], 0, 'tx_test', 1, 'check', []);
		$this->assertFalse($result);
	}

	public function testMapRecordAppliesRenaming(): void {
		$record = ['title' => 'Test'];
		$result = $this->mapper->mapRecord('tx_test', $record, ['title'], renamedFields: ['title' => 'new_title']);

		$this->assertArrayHasKey('new_title', $result);
		$this->assertEquals('Test', $result['new_title']);
		$this->assertArrayNotHasKey('title', $result);
	}

	public function testEnsureParseFuncConfigurationSetsFallbackForEmptyParseFuncConfiguration(): void {
		$previousTsfe = $GLOBALS['TSFE'] ?? NULL;
		$previousRequest = $GLOBALS['TYPO3_REQUEST'] ?? NULL;

		try {
			$GLOBALS['TSFE'] = (object) [
				'tmpl' => (object) [
					'setup' => [
						'lib.' => [
							'parseFunc_RTE.' => []
						]
					]
				]
			];
			unset($GLOBALS['TYPO3_REQUEST']);

			$method = new \ReflectionMethod(TcaMapper::class, 'ensureParseFuncConfiguration');
			$method->setAccessible(TRUE);
			$method->invoke($this->mapper);

			$this->assertArrayHasKey('parseFunc_RTE.', $GLOBALS['TSFE']->tmpl->setup['lib.']);
			$this->assertGreaterThan(1, count($GLOBALS['TSFE']->tmpl->setup['lib.']['parseFunc_RTE.']));
		} finally {
			if ($previousTsfe !== NULL) {
				$GLOBALS['TSFE'] = $previousTsfe;
			} else {
				unset($GLOBALS['TSFE']);
			}

			if ($previousRequest !== NULL) {
				$GLOBALS['TYPO3_REQUEST'] = $previousRequest;
			} else {
				unset($GLOBALS['TYPO3_REQUEST']);
			}
		}
	}
}
