<?php

return [
	'apicore_clear_cache' => [
		'path' => '/apicore/clear-cache',
		'target' => \SGalinski\SgApiCore\Controller\Backend\Ajax\CacheController::class . '::clearCacheAction',
	],
];
