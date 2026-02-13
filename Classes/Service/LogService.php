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

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use SGalinski\SgApiCore\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Service for logging API requests and responses
 */
class LogService implements SingletonInterface {
	/**
	 * @var LoggerInterface
	 */
	protected LoggerInterface $logger;

	/**
	 * @var ExtensionConfiguration
	 */
	protected ExtensionConfiguration $extensionConfiguration;

	/**
	 * @param LogManager|null $logManager
	 * @param ExtensionConfiguration|null $extensionConfiguration
	 */
	public function __construct(?LogManager $logManager = NULL, ?ExtensionConfiguration $extensionConfiguration = NULL) {
		$logManager ??= GeneralUtility::makeInstance(LogManager::class);
		$this->logger = $logManager->getLogger(__CLASS__);
		$this->extensionConfiguration = $extensionConfiguration ?? GeneralUtility::makeInstance(ExtensionConfiguration::class);
	}

	/**
	 * Logs an error message
	 *
	 * @param string $message
	 * @param array $context
	 * @return void
	 */
	public function logError(string $message, array $context = []): void {
		$this->log($message, $context, 'error');
	}

	/**
	 * Logs an info message
	 *
	 * @param string $message
	 * @param array $context
	 * @return void
	 */
	public function logInfo(string $message, array $context = []): void {
		$this->log($message, $context, 'info');
	}

	/**
	 * Internal log method
	 *
	 * @param string $message
	 * @param array $context
	 * @param string $level
	 * @return void
	 */
	protected function log(string $message, array $context = [], string $level = 'info'): void {
		if (!$this->extensionConfiguration->isLoggingEnabled()) {
			return;
		}

		$this->logger->log($level, $message, $context);
	}

	/**
	 * Logs an exception
	 *
	 * @param \Throwable $exception
	 * @param ServerRequestInterface|null $request
	 * @return void
	 */
	public function logException(\Throwable $exception, ?ServerRequestInterface $request = NULL): void {
		if (!$this->extensionConfiguration->isLoggingEnabled()) {
			return;
		}

		$context = [
			'exception' => $exception->getMessage(),
			'file' => $exception->getFile(),
			'line' => $exception->getLine(),
			'trace' => $exception->getTraceAsString(),
		];

		if ($request) {
			$context['requestId'] = (string) $request->getAttribute('api.requestId', '');
			$context['method'] = $request->getMethod();
			$context['path'] = $request->getUri()->getPath();
			$language = $request->getAttribute('language');
			if ($language instanceof \TYPO3\CMS\Core\Site\Entity\SiteLanguage) {
				$context['languageId'] = $language->getLanguageId();
			}
		}

		$this->logger->critical($exception->getMessage(), $context);
	}

	/**
	 * Logs an API request and response
	 *
	 * @param ServerRequestInterface $request
	 * @param ResponseInterface $response
	 * @param float $duration Duration in seconds
	 * @return void
	 */
	public function logRequestResponse(
		ServerRequestInterface $request,
		ResponseInterface $response,
		float $duration
	): void {
		if (!$this->extensionConfiguration->isLoggingEnabled()) {
			return;
		}

		$redactKeys = $this->extensionConfiguration->getRedactKeys();
		$maxBodyLength = $this->extensionConfiguration->getLogBodyMaxLength();
		$requestId = (string) $request->getAttribute('api.requestId', '');
		$apiId = (string) $request->getAttribute('api.id', 'unknown');
		$tenant = $request->getAttribute('api.tenant');
		$tenantId = $tenant?->getTenantId() ?? 'none';

		$context = [
			'requestId' => $requestId,
			'apiId' => $apiId,
			'tenantId' => $tenantId,
			'method' => $request->getMethod(),
			'path' => $request->getUri()->getPath(),
			'status' => $response->getStatusCode(),
			'duration' => round($duration * 1000, 2) . 'ms',
		];

		$language = $request->getAttribute('language');
		if ($language instanceof \TYPO3\CMS\Core\Site\Entity\SiteLanguage) {
			$context['languageId'] = $language->getLanguageId();
		}

		if ($this->extensionConfiguration->isLogHeadersEnabled()) {
			$context['requestHeaders'] = $this->redact($request->getHeaders(), $redactKeys);
		}

		if ($this->extensionConfiguration->isLogBodyEnabled()) {
			$body = $request->getParsedBody();
			if ($body) {
				$context['requestBody'] = $this->truncateLogData($this->redact($body, $redactKeys), $maxBodyLength);
			} else {
				$rawBody = (string) $request->getBody();
				if ($rawBody !== '') {
					$context['requestBody'] = $this->truncateLogData(
						$this->redact($rawBody, $redactKeys),
						$maxBodyLength
					);
				}
			}
		}

		if ($this->extensionConfiguration->isLogResponseEnabled()) {
			$responseBody = (string) $response->getBody();
			if ($responseBody !== '') {
				$context['responseBody'] = $this->truncateLogData(
					$this->redact($responseBody, $redactKeys),
					$maxBodyLength
				);
			}
		}

		$this->logger->info(
			sprintf(
				'API Request: %s %s - Status %d - %s',
				$context['method'],
				$context['path'],
				$context['status'],
				$context['duration']
			),
			$context
		);
	}

	/**
	 * Masks sensitive data in an array or string
	 *
	 * @param mixed $data
	 * @param array $redactKeys
	 * @return mixed
	 */
	public function redact(mixed $data, array $redactKeys): mixed {
		if ($data === NULL || $data === '') {
			return $data;
		}

		$redactKeys = array_map('strtolower', $redactKeys);
		if (is_string($data)) {
			// Basic masking for strings if they look like JSON
			if (str_starts_with($data, '{') || str_starts_with($data, '[')) {
				try {
					$decoded = json_decode($data, TRUE, 512, JSON_THROW_ON_ERROR);
					return json_encode($this->redact($decoded, $redactKeys), JSON_THROW_ON_ERROR);
				} catch (\JsonException) {
					return $data;
				}
			}

			// Redact potential Bearer tokens in strings
			return (string) preg_replace('/(Bearer\s+)[a-zA-Z0-9\._\-]+/', '$1***REDACTED***', $data);
		}

		if (is_array($data)) {
			foreach ($data as $key => $value) {
				$stringKey = strtolower((string) $key);
				$shouldRedact = in_array($stringKey, $redactKeys, TRUE);

				// Special case for Authorization header or similar
				if (!$shouldRedact && ($stringKey === 'authorization' || $stringKey === 'http_authorization')) {
					$shouldRedact = TRUE;
				}

				if ($shouldRedact) {
					if (is_array($value)) {
						$data[$key] = ['***REDACTED***'];
					} else {
						$data[$key] = '***REDACTED***';
					}
				} elseif (is_array($value)) {
					$data[$key] = $this->redact($value, $redactKeys);
				} elseif (is_string($value)) {
					$data[$key] = $this->redact($value, $redactKeys);
				}
			}
		}

		return $data;
	}

	/**
	 * @param mixed $data
	 * @param int $maxLength
	 * @return mixed
	 */
	protected function truncateLogData(mixed $data, int $maxLength): mixed {
		if ($maxLength <= 0) {
			return $data;
		}

		if (is_string($data)) {
			return $this->truncateString($data, $maxLength);
		}

		if (is_array($data)) {
			$encoded = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
			if (is_string($encoded) && strlen($encoded) > $maxLength) {
				return $this->truncateString($encoded, $maxLength);
			}
		}

		return $data;
	}

	/**
	 * @param string $value
	 * @param int $maxLength
	 * @return string
	 */
	protected function truncateString(string $value, int $maxLength): string {
		if (strlen($value) <= $maxLength) {
			return $value;
		}

		return substr($value, 0, $maxLength) . '... [truncated]';
	}
}
