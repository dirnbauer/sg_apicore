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

use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\SingletonInterface;

/**
 * Service to provide the cache path for the API core
 */
class CachePathService implements SingletonInterface {
	/**
	 * Returns the path to the FastRoute cache directory and ensures it exists.
	 *
	 * @return string
	 */
	public function getFastRouteCacheDirectory(): string {
		try {
			$varPath = Environment::getVarPath();
		} catch (\Throwable) {
			$varPath = '';
		}

		if ($varPath !== '') {
			$cacheDirectory = rtrim($varPath, '/') . '/cache/sg_apicore';
		} else {
			$cacheDirectory = rtrim(sys_get_temp_dir(), '/') . '/sg_apicore_cache';
		}

		if (!is_dir($cacheDirectory) && !@mkdir($cacheDirectory, 0775, TRUE) && !is_dir($cacheDirectory)) {
			// Fallback to system temp if var path failed or is not writable
			$cacheDirectory = rtrim(sys_get_temp_dir(), '/') . '/sg_apicore_cache';
			if (!is_dir($cacheDirectory) && !@mkdir($cacheDirectory, 0775, TRUE) && !is_dir($cacheDirectory)) {
				// We can't do much more here if even system temp fails
			}
		}

		return $cacheDirectory;
	}

	/**
	 * Returns all potential cache directories for cleaning purposes
	 *
	 * @return array
	 */
	public function getCacheDirectoriesToClear(): array {
		$directories = [];
		try {
			$varPath = Environment::getVarPath();
			if ($varPath !== '') {
				$directories[] = rtrim($varPath, '/') . '/cache/sg_apicore';
			}
		} catch (\Throwable) {
		}

		$directories[] = rtrim(sys_get_temp_dir(), '/') . '/sg_apicore_cache';
		return array_unique($directories);
	}
}
