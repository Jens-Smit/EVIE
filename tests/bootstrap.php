<?php

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

if (file_exists(dirname(__DIR__).'/config/bootstrap.php')) {
    require dirname(__DIR__).'/config/bootstrap.php';
} else {
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
}

// ========================================================================
// EVIE AI Komponenten - Test-Umgebungsvariablen
// ========================================================================

// Setze Test-Umgebungsvariablen für AI-Komponenten
putenv('APP_ENV=test');

// API Keys für externe Services (Mock-Werte für Tests)
putenv('MISTRAL_API_KEY=test_mistral_api_key');
putenv('GEMINI_API_KEY=test_gemini_api_key');
putenv('FIRECRAWL_API_KEY=test_firecrawl_api_key');
putenv('TAVILY_API_KEY=test_tavily_api_key');
putenv('LINKEDIN_API_TOKEN=test_linkedin_api_token');
putenv('GITHUB_MCP_URL=http://localhost:8080');

// Datenbank-Konfiguration für Tests
putenv('DATABASE_URL=sqlite:///:memory:');

// ========================================================================
// Symfony AI Bundle - Test-Konfiguration
// ========================================================================

// Deaktiviere Deprecation Warnings für Tests
putenv('SYMFONY_DEPRECATIONS_HELPER=weak_vendors');

// Setze Kernel-Klasse für Tests
putenv('KERNEL_CLASS=App\Kernel');
