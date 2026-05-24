<?php

use SGalinski\SgApiCore\Controller\Backend\Ajax\CacheController;

return [
	'apicore_clear_cache' => [
		'path' => '/apicore/clear-cache',
		'target' => CacheController::class . '::clearCacheAction',
	],
];
