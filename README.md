# Ext: sg_apicore

<img src="https://www.sgalinski.de/typo3conf/ext/project_theme/Resources/Public/Images/logo.svg" />

License: [GNU GPL, Version 2](https://www.gnu.org/licenses/gpl-2.0.html)

Repository: https://gitlab.sgalinski.de/typo3/sg_apicore

Please report bugs here: https://gitlab.sgalinski.de/typo3/sg_apicore/-/issues

## Short Summary

Provides an API framework for TYPO3: routing, logging, FE user/bearer auth, entity registration (field whitelist),
pagination, custom endpoints and CRUD permissions.

## Installation

1. Install the extension via composer:
   ```bash
   composer require sgalinski/sg-apicore
   ```

2. Activate the extension in the TYPO3 Extension Manager.

## Testing

You can test the API by calling the health endpoint:

```bash
curl http://your-project.local/api/health
```

The API path prefix is configurable via the extension configuration (default: `/api/`).

## Development

Run the following commands in the project root for code quality checks:

```bash
composer ecs
composer phpstan
composer phpunit -- local/sg-apicore/tests/Unit/
```
