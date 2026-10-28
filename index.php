<?php
// ─────────────────────────────────────────────────────────────
// PONTO DE ENTRADA DO MÓDULO DE INTEGRAÇÕES (index.php)
// Sellex Social Integrations & n8n Automation Engine (v1.0)
// Localização: /api/integrations/index.php
// ─────────────────────────────────────────────────────────────

// 1. Carregar Configurações e Utilitários Essenciais
require_once __DIR__ . "/config/app.php";
require_once __DIR__ . "/config/database.php";
require_once __DIR__ . "/config/secrets.php";
require_once __DIR__ . "/utils/JsonResponse.php";
require_once __DIR__ . "/routes/api.php";

// 2. Configuração de Cabeçalhos HTTP e CORS Seguro
$origin = $_SERVER["HTTP_ORIGIN"] ?? "";
$allowedOrigins = [
    "https://app.sellex.ao",
    "https://sellex.ao",
    "https://api.sellex.ao",
    "https://backoffice.sellex.ao",
    "https://flyer.sellex.ao",
    "http://localhost",
    "http://localhost:3000",
    "http://127.0.0.1"
];

$isAllowedOrigin = false;
if (!empty($origin)) {
    $originHost = parse_url($origin, PHP_URL_HOST);
    $serverHost = $_SERVER["SERVER_NAME"] ?? "localhost";
    
    if (in_array($origin, $allowedOrigins, true) || $originHost === $serverHost || ($originHost && (preg_match('/\.sellex\.ao$/', $originHost) || preg_match('/\.sellex\.app$/', $originHost)))) {
        header("Access-Control-Allow-Origin: " . htmlspecialchars($origin, ENT_QUOTES, "UTF-8"));
        header("Access-Control-Allow-Credentials: true");
        $isAllowedOrigin = true;
    } else {
        header("Access-Control-Allow-Origin: *");
    }
} else {
    header("Access-Control-Allow-Origin: *");
}

header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");

// Permitir todos os cabeçalhos solicitados pela aplicação e autenticação Sellex
$baseAllowedHeaders = "Content-Type, Authorization, X-Authorization, X-Requested-With, X-N8N-Service-Token, X-Hub-Signature-256, Accept, Origin, Access-Control-Allow-Headers";
$requestHeaders = $_SERVER["HTTP_ACCESS_CONTROL_REQUEST_HEADERS"] ?? "";
if (!empty($requestHeaders)) {
    header("Access-Control-Allow-Headers: " . $baseAllowedHeaders . ", " . $requestHeaders);
} else {
    header("Access-Control-Allow-Headers: " . $baseAllowedHeaders);
}

header("Access-Control-Max-Age: 86400");

// Responder imediatamente a requisições de pré-voo (OPTIONS)
if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

// 3. Extração e Normalização da Rota Solicitada
$requestUri = parse_url($_SERVER["REQUEST_URI"] ?? "/", PHP_URL_PATH) ?: "/";
$requestMethod = $_SERVER["REQUEST_METHOD"] ?? "GET";

// Prefixos suportados para roteamento transparente local e em produção
$prefixes = [
    "/app-sellex/api/integrations",
    "/api/integrations",
    "/integrations"
];

$cleanPath = $requestUri;
foreach ($prefixes as $prefix) {
    if (strpos($cleanPath, $prefix) === 0) {
        $cleanPath = substr($cleanPath, strlen($prefix));
        break;
    }
}
$cleanPath = preg_replace('#^/index\.php#', '', $cleanPath);
$cleanPath = "/" . trim($cleanPath, "/");

// 4. Obter corpo da requisição (JSON)
$rawInput = file_get_contents("php://input");
$body = [];
if (!empty($rawInput)) {
    $decoded = json_decode($rawInput, true);
    if (is_array($decoded)) {
        $body = $decoded;
    }
}

// 5. Executar Router com Tratamento Global de Exceções
try {
    ApiRouter::dispatch($requestMethod, $cleanPath, $body);
} catch (Throwable $e) {
    error_log("[SELLEX INTEGRATIONS ERROR] " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
    JsonResponse::serverError("Erro ao processar requisição no módulo de integrações.");
}
