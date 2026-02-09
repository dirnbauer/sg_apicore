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

namespace SGalinski\SgApiCore\Controller;

use Doctrine\DBAL\Exception;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Random\RandomException;
use SGalinski\SgAccount\AccountConfiguration\ConfigurationFactory;
use SGalinski\SgApiCore\Attribute\ApiBodyParam;
use SGalinski\SgApiCore\Attribute\ApiEndpoint;
use SGalinski\SgApiCore\Attribute\ApiLegacyMode;
use SGalinski\SgApiCore\Attribute\ApiResponse;
use SGalinski\SgApiCore\Attribute\ApiRoute;
use SGalinski\SgApiCore\Domain\Repository\TokenRepository;
use SGalinski\SgApiCore\Service\ApiRegistry;
use SGalinski\SgApiCore\Service\ResponseService;
use SGalinski\SgApiCore\Service\TokenService;
use TYPO3\CMS\Core\Crypto\PasswordHashing\InvalidPasswordHashException;
use TYPO3\CMS\Core\Crypto\PasswordHashing\PasswordHashFactory;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Controller for User Authentication (Login & Refresh)
 */
class UserAuthController {
	/**
	 * @var TokenRepository
	 */
	protected TokenRepository $tokenRepository;

	/**
	 * @var TokenService
	 */
	protected TokenService $tokenService;

	/**
	 * @var ApiRegistry
	 */
	protected ApiRegistry $apiRegistry;

	/**
	 * @var PasswordHashFactory
	 */
	protected PasswordHashFactory $passwordHashFactory;

	/**
	 * @var ConnectionPool
	 */
	protected ConnectionPool $connectionPool;

	/**
	 * @var ResponseService
	 */
	protected ResponseService $responseService;

	/**
	 * @param TokenRepository $tokenRepository
	 * @param TokenService $tokenService
	 * @param ApiRegistry $apiRegistry
	 * @param PasswordHashFactory $passwordHashFactory
	 * @param ConnectionPool $connectionPool
	 * @param ResponseService $responseService
	 */
	public function __construct(
		TokenRepository $tokenRepository,
		TokenService $tokenService,
		ApiRegistry $apiRegistry,
		PasswordHashFactory $passwordHashFactory,
		ConnectionPool $connectionPool,
		ResponseService $responseService
	) {
		$this->tokenRepository = $tokenRepository;
		$this->tokenService = $tokenService;
		$this->apiRegistry = $apiRegistry;
		$this->passwordHashFactory = $passwordHashFactory;
		$this->connectionPool = $connectionPool;
		$this->responseService = $responseService;
	}

	/**
	 * Authenticates a user and returns access and refresh tokens
	 *
	 * @param ServerRequestInterface $request
	 * @return ResponseInterface
	 * @throws Exception
	 * @throws RandomException
	 * @throws \JsonException
	 * @throws InvalidPasswordHashException
	 */
	#[ApiRoute(path: '/auth/login', methods: ['POST'], authMode: ['user', 'public'])]
	#[ApiEndpoint(summary: 'User login', description: 'Authenticates a user with username and password and returns access and refresh tokens.', tags: ['Authentication'])]
	#[ApiBodyParam(name: 'username', type: 'string', description: 'The username of the user')]
	#[ApiBodyParam(name: 'password', type: 'string', description: 'The password of the user')]
	#[ApiResponse(status: 200, description: 'Login successful, returns tokens')]
	#[ApiResponse(status: 400, description: 'Missing username or password')]
	#[ApiResponse(status: 401, description: 'Invalid credentials')]
	public function login(ServerRequestInterface $request): ResponseInterface {
		return $this->handleLogin($request);
	}

	/**
	 * Legacy login action for sg_rest compatibility
	 *
	 * @param ServerRequestInterface $request
	 * @return ResponseInterface
	 * @throws Exception
	 * @throws RandomException
	 * @throws \JsonException
	 * @throws InvalidPasswordHashException
	 */
	#[ApiRoute(path: '/auth/legacyLogin', methods: ['POST'], apiId: 'legacy', authMode: ['user', 'public'])]
	#[ApiEndpoint(summary: 'Legacy User login', description: 'Authenticates a user and returns a bearer token in the legacy sg_rest format.', tags: ['Legacy'])]
	#[ApiBodyParam(name: 'username', type: 'string', description: 'The username of the user')]
	#[ApiBodyParam(name: 'password', type: 'string', description: 'The password of the user')]
	#[ApiResponse(status: 200, description: 'Login successful, returns bearerToken')]
	#[ApiLegacyMode(wrapData: FALSE)]
	public function legacyLogin(ServerRequestInterface $request): ResponseInterface {
		// Ensure we are in the legacy API context
		if ($request->getAttribute('api.id') !== 'legacy') {
			return $this->responseService->createErrorResponse(
				'Forbidden',
				'This endpoint is only available for the legacy API.',
				403
			);
		}

		$response = $this->handleLogin($request);
		if ($response->getStatusCode() === 200) {
			$data = json_decode((string) $response->getBody(), TRUE, 512, JSON_THROW_ON_ERROR);
			return $this->responseService->createSuccessResponse([
				'bearerToken' => $data['access_token'] ?? ''
			], [], 200, new ApiLegacyMode(wrapData: FALSE));
		}

		return $response;
	}

	/**
	 * Shared login logic
	 *
	 * @param ServerRequestInterface $request
	 * @return ResponseInterface
	 * @throws Exception
	 * @throws InvalidPasswordHashException
	 * @throws RandomException
	 * @throws \JsonException
	 */
	protected function handleLogin(ServerRequestInterface $request): ResponseInterface {
		$params = $request->getParsedBody();
		$username = $params['username'] ?? $params['user'] ?? '';
		$password = $params['password'] ?? $params['pass'] ?? '';

		if ($username === '' || $password === '') {
			return $this->responseService->createErrorResponse('Bad Request', 'Missing username or password.', 400);
		}

		/** @var \SGalinski\SgApiCore\Context\TenantContext|null $tenantContext */
		$tenantContext = $request->getAttribute('api.tenant');
		$siteRootPageId = $tenantContext?->getSiteRootPageId();
		$apiId = (string) $request->getAttribute('api.id');
		$version = $request->getAttribute('api.version') ?? '1';

		$storagePids = [];
		if ($siteRootPageId > 0) {
			$storagePids[] = (int) $siteRootPageId;
		}

		// EXT:sg_account support
		if (class_exists(ConfigurationFactory::class)) {
			try {
				$accountConfiguration = ConfigurationFactory::getMainConfiguration(0, (int) $siteRootPageId);
				if ($accountConfiguration) {
					$settings = $accountConfiguration->getSettings();
					if (isset($settings['frontendUserStoragePage']) && (int) $settings['frontendUserStoragePage'] > 0) {
						$storagePids = GeneralUtility::intExplode(
							',',
							(string) $settings['frontendUserStoragePage'],
							TRUE
						);
					}
				}
			} catch (\Throwable) {
				// Fallback to site root if sg_account fails
			}
		}

		// Support Site Configuration overrides
		$site = $tenantContext?->getSite();
		if ($site) {
			$siteConfig = $site->getConfiguration();
			if (isset($siteConfig['apicore']['userStoragePids'])) {
				$storagePids = GeneralUtility::intExplode(
					',',
					(string) $siteConfig['apicore']['userStoragePids'],
					TRUE
				);
			}
		}

		// Find user in fe_users
		$queryBuilder = $this->connectionPool->getQueryBuilderForTable('fe_users');
		$queryBuilder->select('*')
			->from('fe_users')
			->where(
				$queryBuilder->expr()->eq('username', $queryBuilder->createNamedParameter($username)),
				$queryBuilder->expr()->eq('disable', 0),
				$queryBuilder->expr()->eq('deleted', 0)
			);

		if (count($storagePids) > 0) {
			$queryBuilder->andWhere(
				$queryBuilder->expr()->in(
					'pid',
					$queryBuilder->createNamedParameter($storagePids, Connection::PARAM_INT_ARRAY)
				)
			);
		}

		$user = $queryBuilder->executeQuery()->fetchAssociative();
		if (!$user) {
			return $this->responseService->createErrorResponse('Unauthorized', 'Invalid credentials.', 401);
		}

		// Verify password
		$hashInstance = $this->passwordHashFactory->get($user['password'], 'FE');
		if (!$hashInstance->checkPassword($password, $user['password'])) {
			return $this->responseService->createErrorResponse('Unauthorized', 'Invalid credentials.', 401);
		}

		// Success! Generate tokens
		$tenantId = $tenantContext?->getTenantId() ?? '';
		$securityConfig = $this->apiRegistry->getSecurityConfig($apiId, (string) $version);
		$activeProviders = $securityConfig['authProviders'] ?? [];
		$useJwt = in_array('jwtaccesstokenprovider', array_map('strtolower', $activeProviders), TRUE);

		// Default scopes (maybe from user record or API config?)
		$scopes = ['user']; // Default scope for user login

		// Create Refresh Token (Opaque)
		$refreshToken = $this->tokenService->generateRandomToken();
		$this->tokenService->createToken(
			$refreshToken,
			$apiId,
			$tenantId,
			(int) $siteRootPageId,
			$scopes,
			(int) $user['uid'],
			TRUE,
			time() + (30 * 24 * 3600), // 30 days TTL for refresh token
			'Refresh Token for ' . $user['username']
		);

		// Create Access Token
		if ($useJwt) {
			$accessToken = $this->tokenService->generateJwtAccessToken(
				(int) $user['uid'],
				$apiId,
				$tenantId,
				$scopes,
				'login-' . $user['uid'] . '-' . time()
			);
		} else {
			$accessToken = $this->tokenService->generateRandomToken();
			$this->tokenService->createToken(
				$accessToken,
				$apiId,
				$tenantId,
				(int) $siteRootPageId,
				$scopes,
				(int) $user['uid'],
				FALSE,
				time() + 3600, // 1-hour TTL for opaque access token
				'Access Token for ' . $user['username']
			);
		}

		return $this->responseService->createSuccessResponse([
			'access_token' => $accessToken,
			'refresh_token' => $refreshToken,
			'token_type' => 'Bearer',
			'expires_in' => 3600
		]);
	}

	/**
	 * Refreshes an access token using a refresh token
	 *
	 * @param ServerRequestInterface $request
	 * @return ResponseInterface
	 * @throws Exception
	 * @throws RandomException
	 * @throws \JsonException
	 */
	#[ApiRoute(path: '/auth/refresh', methods: ['POST'], authMode: ['user', 'public'])]
	#[ApiEndpoint(summary: 'Refresh access token', description: 'Exchange a refresh token for a new access token.', tags: ['Authentication'])]
	#[ApiBodyParam(name: 'refresh_token', type: 'string', description: 'The refresh token obtained during login')]
	#[ApiResponse(status: 200, description: 'Success, returns a new access token')]
	#[ApiResponse(status: 400, description: 'Missing refresh_token parameter')]
	#[ApiResponse(status: 401, description: 'Invalid or expired refresh token')]
	public function refresh(ServerRequestInterface $request): ResponseInterface {
		$params = $request->getParsedBody();
		$refreshToken = $params['refresh_token'] ?? '';

		if ($refreshToken === '') {
			return $this->responseService->createErrorResponse('Bad Request', 'Missing refresh_token parameter.', 400);
		}

		/** @var \SGalinski\SgApiCore\Context\TenantContext|null $tenantContext */
		$tenantContext = $request->getAttribute('api.tenant');
		$apiId = (string) $request->getAttribute('api.id');
		$tenantId = $tenantContext?->getTenantId() ?? '';
		$siteRootPageId = $tenantContext?->getSiteRootPageId();

		$refreshTokenHash = hash('sha256', $refreshToken);
		$tokenRecord = $this->tokenRepository->findByHashApiAndTenant(
			$refreshTokenHash,
			$apiId,
			$tenantId,
			$siteRootPageId,
			TRUE
		);

		if ($tokenRecord === NULL || !$tokenRecord['is_refresh_token']) {
			return $this->responseService->createErrorResponse('Unauthorized', 'Invalid refresh token.', 401);
		}

		// Check expiry
		if ((int) $tokenRecord['expires_at'] > 0 && (int) $tokenRecord['expires_at'] < time()) {
			return $this->responseService->createErrorResponse('Unauthorized', 'Refresh token expired.', 401);
		}

		$scopes = [];
		if ($tokenRecord['scopes']) {
			$scopes = json_decode($tokenRecord['scopes'], TRUE, 512, JSON_THROW_ON_ERROR);
		}

		$userId = (int) $tokenRecord['user_id'];
		$version = $request->getAttribute('api.version') ?? '1';

		// Check if we should use JWT or Opaque Access Token
		$securityConfig = $this->apiRegistry->getSecurityConfig($apiId, (string) $version);
		$activeProviders = $securityConfig['authProviders'] ?? [];
		$useJwt = in_array('jwtaccesstokenprovider', array_map('strtolower', $activeProviders), TRUE);

		if ($useJwt) {
			$accessToken = $this->tokenService->generateJwtAccessToken(
				$userId,
				$apiId,
				$tenantId,
				$scopes,
				'refresh-' . $tokenRecord['uid'] . '-' . time()
			);
		} else {
			$accessToken = $this->tokenService->generateRandomToken();
			$this->tokenService->createToken(
				$accessToken,
				$apiId,
				$tenantId,
				(int) $siteRootPageId,
				$scopes,
				$userId,
				FALSE,
				time() + 3600, // 1-hour TTL for opaque access token
				'Access Token (Refreshed from ' . $tokenRecord['uid'] . ')'
			);
		}

		// Update last used on the refresh token
		$this->tokenRepository->updateLastUsed((int) $tokenRecord['uid']);

		return $this->responseService->createSuccessResponse([
			'access_token' => $accessToken,
			'token_type' => 'Bearer',
			'expires_in' => 3600
		]);
	}
}
