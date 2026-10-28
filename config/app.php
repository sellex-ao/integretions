<?php
// ─────────────────────────────────────────────────────────────
// CONFIGURAÇÃO GERAL DO MÓDULO DE INTEGRAÇÕES (app.php)
// Sellex Social Integrations & n8n Automation Engine
// Localização: /api/integrations/config/app.php
// ─────────────────────────────────────────────────────────────

// Configurar fuso horário oficial de Angola (GMT+1)
date_default_timezone_set("Africa/Luanda");

// Carregar variáveis de ambiente de /api/integrations/.env, /api/.env ou /.env
$envCandidates = [
    __DIR__ . "/../.env",
    __DIR__ . "/../../.env",
    __DIR__ . "/../../../.env"
];

foreach ($envCandidates as $envFile) {
    if (file_exists($envFile)) {
        $envLines = @file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($envLines) {
            foreach ($envLines as $line) {
                $line = trim($line);
                if (empty($line) || $line[0] === "#") continue;
                if (strpos($line, "=") !== false) {
                    list($key, $value) = explode("=", $line, 2);
                    $key = trim($key);
                    $value = trim($value);
                    if ((str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                        (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
                        $value = substr($value, 1, -1);
                    }
                    if (!getenv($key)) {
                        putenv("{$key}={$value}");
                        $_ENV[$key] = $value;
                    }
                }
            }
        }
    }
}

return [
    "service" => "sellex-integrations",
    "version" => "v1",
    "name" => "Sellex Social & n8n Integration API",
    "env" => getenv("APP_ENV") ?: "production",
    "debug" => (bool)(getenv("APP_DEBUG") ?: false),
    "base_url" => getenv("INTEGRATIONS_BASE_URL") ?: (getenv("API_URL") ? rtrim(getenv("API_URL"), "/api-app") . "/integrations/v1" : "https://api.sellex.ao/integrations/v1"),
    "supported_providers" => ["facebook", "instagram", "whatsapp"],
    "allowed_origins" => [
        "https://app.sellex.ao",
        "https://sellex.ao",
        "https://api.sellex.ao",
        "http://localhost",
        "http://localhost:3000",
        "http://127.0.0.1"
    ]
];
