# Logging

`sg_apicore` offers a comprehensive logging system for API requests and responses. It is designed to ensure traceability
without compromising sensitive data.
The defaults below match the TYPO3 `14.x` release line.

## Global Configuration

Logging can be controlled in the extension configuration (`settings.php` or Extension Manager):

* `enableLogging`: Global switch for API logging.
* `logHeaders`: Whether HTTP headers should be logged.
* `logBody`: Whether the request body should be logged.
* `logResponse`: Whether the response body should be logged.
* `redactKeys`: Comma-separated list of keys whose values are masked in the logs (e.g., `password,token,access_token`).

## Per-Endpoint Configuration

With the `#[ApiLogging]` attribute, the global settings can be overridden for individual methods:

```php
use SGalinski\SgApiCore\Attribute\ApiLogging;

#[ApiLogging(
    enableLogging: true,
    logHeaders: true,
    logBody: true,
    logResponse: true
)]
public function sensitiveAction(...) { ... }
```

## Request Tracking

Every API request receives a unique **Request ID** (Correlation ID). This ID:

1. Is attached to the request as the `api.requestId` attribute.
2. Is included in every log message.
3. Is returned as an `X-Request-ID` HTTP header in the response.

This allows an API call to be tracked from the client deep into the server logs.
Error responses include the same request ID so support teams can correlate client reports with log entries.

## Customizing the Log Destination

By default, logs are written to the file `var/log/sg_apicore.log`. This can be adjusted via the TYPO3 log configuration:

```php
$GLOBALS['TYPO3_CONF_VARS']['LOG']['SGalinski']['SgApiCore']['Service']['LogService']['writerConfiguration'] = [
    \Psr\Log\LogLevel::INFO => [
        \TYPO3\CMS\Core\Log\Writer\FileWriter::class => [
            'logFile' => \TYPO3\CMS\Core\Core\Environment::getVarPath() . '/log/custom_api.log'
        ]
    ]
];
```
