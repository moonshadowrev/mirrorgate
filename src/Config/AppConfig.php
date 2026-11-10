<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace MirrorGate\Config;

final readonly class AppConfig
{
    public function __construct(
        public string $env,                 // APP_ENV: production|development
        public bool $debug,                 // APP_DEBUG
        public string $instanceId,          // APP_INSTANCE_ID
        public string $timezone,            // APP_TIMEZONE
        public string $storageDir,          // STORAGE_DIR (absolute)
        public string $logLevel,            // LOG_LEVEL
        public string $logFile,             // LOG_FILE (absolute)
        public int $logMaxBytes,            // LOG_MAX_BYTES
        public string $errorPageLang,       // ERROR_PAGE_LANG: en|fa
        public ?string $errorPageDir,       // ERROR_PAGE_DIR
        public string $errorPageUpstream,   // ERROR_PAGE_UPSTREAM: gateway|all|off
        public bool $exposeCacheHeaders,    // EXPOSE_CACHE_HEADERS
        public bool $addVia,                // ADD_VIA
        public string $logPathMode = 'segments',      // LOG_PATH_MODE: segments|hash|full
        public bool $allowSecretsInDocroot = false,   // ALLOW_SECRETS_IN_DOCROOT
        public int $storageFileMode = 0640,           // STORAGE_FILE_MODE
        public int $storageDirMode = 0750,            // STORAGE_DIR_MODE
    ) {
    }
}
