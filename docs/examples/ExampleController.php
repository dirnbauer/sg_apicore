<?php

namespace Vendor\MyExtension\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SGalinski\SgApiCore\Attribute\ApiBodyParam;
use SGalinski\SgApiCore\Attribute\ApiCache;
use SGalinski\SgApiCore\Attribute\ApiEndpoint;
use SGalinski\SgApiCore\Attribute\ApiPathParam;
use SGalinski\SgApiCore\Attribute\ApiQueryParam;
use SGalinski\SgApiCore\Attribute\ApiResponse;
use SGalinski\SgApiCore\Attribute\ApiRoute;
use SGalinski\SgApiCore\Attribute\RequireFullTypoScript;
use SGalinski\SgApiCore\Attribute\RequireScopes;
use SGalinski\SgApiCore\Attribute\RequireUser;
use SGalinski\SgApiCore\Context\TenantContext;
use SGalinski\SgApiCore\Security\AuthContext;
use SGalinski\SgApiCore\Service\PaginationService;
use SGalinski\SgApiCore\Service\ResponseService;

/**
 * Example controller showing current sg_apicore capabilities.
 */
class ExampleController {
	protected ResponseService $responseService;
	protected PaginationService $paginationService;

	public function __construct(ResponseService $responseService, PaginationService $paginationService) {
		$this->responseService = $responseService;
		$this->paginationService = $paginationService;
	}

	#[ApiRoute(path: '/health', methods: ['GET'])]
	#[ApiEndpoint(summary: 'Global health check', tags: ['System'])]
	#[ApiResponse(status: 200, description: 'API is healthy')]
	public function health(ServerRequestInterface $request): ResponseInterface {
		return $this->responseService->createSuccessResponse([
			'status' => 'ok',
			'service' => 'my_extension',
			'timestamp' => gmdate(DATE_ATOM),
		]);
	}

	#[ApiRoute(path: '/test', methods: ['GET'], apiId: 'public', version: '1')]
	#[ApiRoute(path: '/context', methods: ['GET'], apiId: 'public', version: '1')]
	#[ApiEndpoint(summary: 'Public context endpoint', tags: ['Examples'])]
	#[ApiQueryParam(name: 'code', type: 'string', required: TRUE, pattern: '/^[A-Z]{3}$/', example: 'ABC')]
	#[ApiQueryParam(name: 'includeScopes', type: 'boolean', required: FALSE, example: FALSE)]
	#[ApiResponse(status: 200, description: 'Context payload')]
	public function publicTest(ServerRequestInterface $request): ResponseInterface {
		$params = $request->getQueryParams();
		$code = (string) ($params['code'] ?? '');
		$includeScopes = $this->isTruthy($params['includeScopes'] ?? FALSE);
		$tenantContext = $request->getAttribute('api.tenant');
		$authContext = $request->getAttribute('api.auth');

		$data = [
			'api' => 'public',
			'version' => '1',
			'code' => $code,
			'tenant' => $tenantContext instanceof TenantContext ? $tenantContext->getTenantId() : NULL,
			'requestId' => $request->getAttribute('api.requestId'),
			'message' => 'Public API test successful',
		];

		if ($includeScopes && $authContext instanceof AuthContext) {
			$data['scopes'] = $authContext->getScopes();
		}

		return $this->responseService->createSuccessResponse($data);
	}

	#[ApiRoute(path: '/test', methods: ['GET'], apiId: 'partner', version: '1')]
	#[ApiEndpoint(summary: 'Partner API test endpoint', tags: ['Examples'])]
	#[RequireScopes(['partner:read'])]
	#[ApiResponse(status: 200, description: 'Partner request accepted')]
	public function partnerTest(ServerRequestInterface $request): ResponseInterface {
		$authContext = $request->getAttribute('api.auth');
		$tenantContext = $request->getAttribute('api.tenant');

		return $this->responseService->createSuccessResponse([
			'api' => 'partner',
			'version' => '1',
			'tenant' => $tenantContext instanceof TenantContext ? $tenantContext->getTenantId() : NULL,
			'tokenUid' => $authContext instanceof AuthContext ? $authContext->getTokenUid() : NULL,
			'userId' => $authContext instanceof AuthContext ? $authContext->getUserId() : NULL,
			'scopes' => $authContext instanceof AuthContext ? $authContext->getScopes() : [],
			'message' => 'Partner API test successful',
		]);
	}

	#[ApiRoute(path: '/user-test', methods: ['GET'], apiId: 'user', version: '1')]
	#[ApiEndpoint(summary: 'User context endpoint', tags: ['Examples'])]
	#[RequireUser]
	#[ApiResponse(status: 200, description: 'User context resolved')]
	public function userTest(ServerRequestInterface $request): ResponseInterface {
		$authContext = $request->getAttribute('api.auth');

		return $this->responseService->createSuccessResponse([
			'api' => 'user',
			'userId' => $authContext instanceof AuthContext ? $authContext->getUserId() : NULL,
			'tokenUid' => $authContext instanceof AuthContext ? $authContext->getTokenUid() : NULL,
			'scopes' => $authContext instanceof AuthContext ? $authContext->getScopes() : [],
			'message' => 'User-Level API test successful',
		]);
	}

	#[ApiRoute(path: '/admin-test', methods: ['GET'], apiId: ['partner', 'user'], version: '1')]
	#[ApiEndpoint(summary: 'Admin scope check', tags: ['Examples'])]
	#[RequireScopes(['admin'])]
	#[ApiResponse(status: 200, description: 'Scope check passed')]
	public function adminTest(ServerRequestInterface $request): ResponseInterface {
		$authContext = $request->getAttribute('api.auth');
		return $this->responseService->createSuccessResponse([
			'message' => 'Admin scope check successful',
			'scopes' => $authContext instanceof AuthContext ? $authContext->getScopes() : [],
		]);
	}

	#[ApiRoute(path: '/examples', methods: ['GET'], apiId: 'public', version: '1')]
	#[ApiEndpoint(
		summary: 'List example items',
		description: 'Provides pagination, query validation and cache control for list endpoints.',
		tags: ['Examples']
	)]
	#[ApiQueryParam(name: 'page', type: 'integer', description: 'Page number (1-based)', min: 1)]
	#[ApiQueryParam(name: 'limit', type: 'integer', description: 'Maximum number of items', min: 1, max: 50)]
	#[ApiQueryParam(name: 'search', type: 'string', description: 'Search by name or type', minLength: 2, maxLength: 80)]
	#[ApiQueryParam(name: 'includeDraft', type: 'boolean', required: FALSE, example: FALSE)]
	#[ApiResponse(status: 200, description: 'List result with pagination metadata', schema: 'ExampleItem[]')]
	#[ApiCache(lifetime: 300, tags: ['example_items'], additionalVary: ['search', 'includeDraft'])]
	public function listAction(ServerRequestInterface $request): ResponseInterface {
		$queryParams = $request->getQueryParams();
		$search = trim((string) ($queryParams['search'] ?? ''));
		$includeDrafts = $this->isTruthy($queryParams['includeDraft'] ?? FALSE);
		$pagination = $this->paginationService->getPaginationParams($request);
		$items = $this->filterExampleItems($this->getExampleItems(), $search, $includeDrafts);

		$total = \count($items);
		$slicedData = \array_slice($items, $pagination['offset'], $pagination['limit']);
		$meta = $this->paginationService->buildPaginationMeta($total, $pagination['offset'], $pagination['limit']);
		$meta['filters'] = [
			'search' => $search,
			'includeDraft' => $includeDrafts,
		];

		return $this->responseService->createSuccessResponse($slicedData, $meta);
	}

	#[ApiRoute(path: '/examples/{id}', methods: ['GET'], apiId: 'public', version: '1')]
	#[ApiEndpoint(
		summary: 'Get one example item',
		description: 'Returns a single list item and demonstrates path validation.',
		tags: ['Examples']
	)]
	#[ApiPathParam(name: 'id', type: 'integer', description: 'Unique item ID', pattern: '/^\d+$/')]
	#[ApiResponse(status: 200, description: 'Single item', schema: 'ExampleItem')]
	#[ApiResponse(status: 400, description: 'Validation failed')]
	#[ApiResponse(status: 404, description: 'Example not found')]
	#[ApiCache(lifetime: 600, tags: ['example_items'])]
	public function getAction(ServerRequestInterface $request, int $id): ResponseInterface {
		foreach ($this->getExampleItems() as $item) {
			if ((int) $item['id'] === $id) {
				return $this->responseService->createSuccessResponse($item);
			}
		}

		return $this->responseService->createErrorResponse(
			'Not Found',
			'The requested example item was not found.',
			404
		);
	}

	#[ApiRoute(path: '/examples', methods: ['POST'], apiId: 'partner', version: '1')]
	#[ApiEndpoint(
		summary: 'Create example item',
		description: 'Demonstrates JSON body validation including requiredIf and typed values.',
		tags: ['Examples']
	)]
	#[RequireScopes(['partner:write'])]
	#[ApiBodyParam(
		name: 'name',
		type: 'string',
		required: TRUE,
		minLength: 3,
		maxLength: 120,
		example: 'Feature Showcase'
	)]
	#[ApiBodyParam(
		name: 'type',
		type: 'string',
		required: TRUE,
		pattern: '/^(video|article|podcast)$/',
		example: 'article'
	)]
	#[ApiBodyParam(name: 'isDraft', type: 'boolean', required: FALSE, example: FALSE)]
	#[ApiBodyParam(name: 'channelId', type: 'integer', required: FALSE, requiredIf: 'type=video', min: 1, example: 42)]
	#[ApiBodyParam(name: 'tags', type: 'array', required: FALSE, example: ['api', 'tutorial'])]
	#[ApiResponse(status: 201, description: 'Example item created')]
	#[ApiResponse(status: 400, description: 'Validation failed')]
	#[ApiResponse(status: 403, description: 'Insufficient scope')]
	public function createAction(ServerRequestInterface $request): ResponseInterface {
		$body = $request->getParsedBody();
		$body = \is_array($body) ? $body : [];

		$item = [
			'id' => ((int) time() % 100000),
			'name' => (string) ($body['name'] ?? ''),
			'type' => (string) ($body['type'] ?? ''),
			'isDraft' => $this->isTruthy($body['isDraft'] ?? FALSE),
			'createdAt' => gmdate(DATE_ATOM),
		];

		if (isset($body['channelId'])) {
			$item['channelId'] = (int) $body['channelId'];
		}
		if (isset($body['tags']) && \is_array($body['tags'])) {
			$item['tags'] = array_values(array_map(static fn ($tag): string => (string) $tag, $body['tags']));
		}

		return $this->responseService->createSuccessResponse($item, [], 201);
	}

	#[ApiRoute(path: '/rendering-context', methods: ['GET'], apiId: 'public', version: '1')]
	#[ApiEndpoint(summary: 'Inspect rendering context', tags: ['Examples'])]
	#[RequireFullTypoScript]
	#[ApiResponse(status: 200, description: 'TypoScript context information')]
	public function renderingContext(ServerRequestInterface $request): ResponseInterface {
		$tenantContext = $request->getAttribute('api.tenant');

		return $this->responseService->createSuccessResponse([
			'hasFrontendTypoScript' => $request->getAttribute('frontend.typoscript') !== NULL,
			'tenant' => $tenantContext instanceof TenantContext ? $tenantContext->getTenantId() : NULL,
		]);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	protected function getExampleItems(): array {
		return [
			['id' => 1, 'name' => 'Homepage Teaser', 'type' => 'article', 'isDraft' => FALSE],
			['id' => 2, 'name' => 'Feature Intro Video', 'type' => 'video', 'isDraft' => FALSE],
			['id' => 3, 'name' => 'Backend Walkthrough', 'type' => 'video', 'isDraft' => TRUE],
			['id' => 4, 'name' => 'API Launch Notes', 'type' => 'article', 'isDraft' => FALSE],
			['id' => 5, 'name' => 'Release Podcast', 'type' => 'podcast', 'isDraft' => FALSE],
			['id' => 6, 'name' => 'Editorial Planning', 'type' => 'article', 'isDraft' => TRUE],
			['id' => 7, 'name' => 'Partner Demo Reel', 'type' => 'video', 'isDraft' => FALSE],
			['id' => 8, 'name' => 'Quarterly Insights', 'type' => 'podcast', 'isDraft' => FALSE],
		];
	}

	/**
	 * @param array<int, array<string, mixed>> $items
	 * @param string $search
	 * @param bool $includeDrafts
	 * @return array<int, array<string, mixed>>
	 */
	protected function filterExampleItems(array $items, string $search, bool $includeDrafts): array {
		$search = strtolower(trim($search));

		return array_values(array_filter($items, static function (array $item) use ($search, $includeDrafts): bool {
			if (!$includeDrafts && !empty($item['isDraft'])) {
				return FALSE;
			}
			if ($search === '') {
				return TRUE;
			}

			$haystack = strtolower((string) ($item['name'] ?? '') . ' ' . (string) ($item['type'] ?? ''));
			return str_contains($haystack, $search);
		}));
	}

	/**
	 * @param mixed $value
	 * @return bool
	 */
	protected function isTruthy(mixed $value): bool {
		return \in_array($value, [TRUE, 1, '1', 'true', 'yes', 'on'], TRUE);
	}
}
