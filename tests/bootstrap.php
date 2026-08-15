<?php

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

// Load .env unless APP_ENV is already set (CI passes it explicitly).
if (file_exists(dirname(__DIR__).'/config/bootstrap.php')) {
    require dirname(__DIR__).'/config/bootstrap.php';
} else {
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
}

// ========================================================================
// E2E test defaults — these ONLY apply when the variable is not already set,
// so that `APP_ENV=dev` / `APP_ENV=prod` from the caller is respected and the
// tests actually run against the dev/prod container configuration.
// ========================================================================

// KernelTestCase::bootKernel() falls back to 'test' when APP_ENV is unset.
// We do NOT override an explicitly provided APP_ENV.
if (!isset($_ENV['APP_ENV']) && !getenv('APP_ENV')) {
    putenv('APP_ENV=test');
}

// AI / external service keys — test-safe defaults only when unset.
// In dev/prod these would come from the real .env; for CI/headless runs we
// provide non-empty placeholders so the container compiles.
foreach ([
    'MISTRAL_API_KEY' => 'test_mistral_api_key',
    'GEMINI_API_KEY' => 'test_gemini_api_key',
    'FIRECRAWL_API_KEY' => 'test_firecrawl_api_key',
    'TAVILY_API_KEY' => 'test_tavily_api_key',
    'LINKEDIN_API_TOKEN' => 'test_linkedin_api_token',
    'GITHUB_MCP_URL' => 'http://localhost:8080',
] as $var => $default) {
    if (!isset($_ENV[$var]) && !getenv($var)) {
        putenv($var.'='.$default);
    }
}

// DATABASE_URL — only default to in-memory SQLite when no explicit DB is
// configured. For dev/prod E2E runs the caller (or .env) supplies a real
// DATABASE_URL (typically an isolated test DB on the same engine).
if (!isset($_ENV['DATABASE_URL']) && !getenv('DATABASE_URL')) {
    putenv('DATABASE_URL=sqlite:///:memory:');
}

// APP_SECRET must be set for the container to compile.
if (!isset($_ENV['APP_SECRET']) && !getenv('APP_SECRET')) {
    putenv('APP_SECRET=test-secret-for-evie-1234567890abcdef');
}

// MAILER must not block container compilation in headless runs.
if (!isset($_ENV['MAILER_DSN']) && !getenv('MAILER_DSN')) {
    putenv('MAILER_DSN=null://null');
}
if (!isset($_ENV['MAILER_FROM']) && !getenv('MAILER_FROM')) {
    putenv('MAILER_FROM=no-reply@evie.test');
}

// Deprecation handling for the test suite.
if (!isset($_ENV['SYMFONY_DEPRECATIONS_HELPER']) && !getenv('SYMFONY_DEPRECATIONS_HELPER')) {
    putenv('SYMFONY_DEPRECATIONS_HELPER=weak_vendors');
}

if (!isset($_ENV['KERNEL_CLASS']) && !getenv('KERNEL_CLASS')) {
    putenv('KERNEL_CLASS=App\\Kernel');
}
