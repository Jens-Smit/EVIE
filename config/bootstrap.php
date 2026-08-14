<?php

// config/bootstrap.php
//
// Loads environment variables from .env files. This mirrors the bootstrap that
// symfony/flex normally generates. It is required because this project uses
// symfony/runtime (autoload_runtime.php), which reads APP_ENV / APP_DEBUG from
// the process environment but does not itself parse .env files.
//
// Load order (first match wins):
//   1. .env.local        (local overrides, gitignored)
//   2. .env.<APP_ENV>     (environment-specific)
//   3. .env               (shared defaults)
//
// Reference: https://symfony.com/doc/current/configuration.html#configuring-env-vars

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

// Allow the environment to be set externally (e.g. in CI/Docker) and skip
// loading .env files entirely if all required vars are already present.
if (class_exists(Dotenv::class)) {
    $projectDir = dirname(__DIR__);
    $dotenv = new Dotenv();

    if (is_file($projectDir.'/.env')) {
        $dotenv->loadEnv($projectDir.'/.env');
    }
}
