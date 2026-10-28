<?php
// ─────────────────────────────────────────────────────────────
// MIDDLEWARE DE AUTENTICAÇÃO DO SERVIÇO N8N (AuthenticateN8n.php)
// Validação Segura de Credencial Dedicada por Bearer Token
// Localização: /api/integrations/middleware/AuthenticateN8n.php
// ─────────────────────────────────────────────────────────────

require_once __DIR__ . "/../config/secrets.php";
require_once __DIR__ . "/../utils/JsonResponse.php";

class AuthenticateN8n {

    /**
     * Valida o token de serviço do n8n no cabeçalho Authorization ou X-N8N-Service-Token
     */
    public static function handle() {
        $headers = self::getRequestHeaders();

        $token = null;
        if (!empty($headers["Authorization"])) {
            $parts = explode(" ", trim($headers["Authorization"]));
            if (count($parts) === 2 && strcasecmp($parts[0], "Bearer") === 0) {
                $token = $parts[1];
            }
        }

        if (!$token && !empty($headers["X-N8N-Service-Token"])) {
            $token = trim($headers["X-N8N-Service-Token"]);
        }

        if (!$token) {
            JsonResponse::unauthorized("Cabeçalho de autorização do n8n em falta.");
        }

        $expectedToken = IntegrationsSecrets::getN8nServiceToken();

        if (!hash_equals($expectedToken, $token)) {
            JsonResponse::unauthorized("Token de autorização do serviço de automação inválido.");
        }

        return true;
    }

    private static function getRequestHeaders() {
        if (function_exists("getallheaders")) {
            $all = getallheaders();
            if ($all) {
                $normalized = [];
                foreach ($all as $k => $v) {
                    $normalized[strtolower($k)] = $v;
                    $normalized[$k] = $v;
                }
                return $normalized;
            }
        }

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (strpos($key, "HTTP_") === 0) {
                $headerName = str_replace(" ", "-", ucwords(strtolower(str_replace("_", " ", substr($key, 5)))));
                $headers[$headerName] = $value;
                $headers[strtolower($headerName)] = $value;
            }
        }
        if (isset($_SERVER["CONTENT_TYPE"])) {
            $headers["Content-Type"] = $_SERVER["CONTENT_TYPE"];
            $headers["content-type"] = $_SERVER["CONTENT_TYPE"];
        }
        if (isset($_SERVER["HTTP_AUTHORIZATION"])) {
            $headers["Authorization"] = $_SERVER["HTTP_AUTHORIZATION"];
            $headers["authorization"] = $_SERVER["HTTP_AUTHORIZATION"];
        }

        return $headers;
    }
}
