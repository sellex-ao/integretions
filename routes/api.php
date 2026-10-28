<?php
// ─────────────────────────────────────────────────────────────
// DEFINIÇÃO DE ROTAS DA API DE INTEGRAÇÕES (api.php)
// Mapeamento de Endpoints, Controladores e Middlewares
// Localização: /api/integrations/routes/api.php
// ─────────────────────────────────────────────────────────────

require_once __DIR__ . "/../controllers/MetaOAuthController.php";
require_once __DIR__ . "/../controllers/MetaWebhookController.php";
require_once __DIR__ . "/../controllers/N8nController.php";
require_once __DIR__ . "/../middleware/AuthenticateN8n.php";
require_once __DIR__ . "/../middleware/RateLimit.php";
require_once __DIR__ . "/../utils/JsonResponse.php";

class ApiRouter {

    /**
     * Despacha a requisição HTTP para a rota correspondente
     * @param string $method Método HTTP (GET, POST, DELETE, etc.)
     * @param string $path Caminho relativo limpo (ex: /health, /meta/oauth/start)
     * @param array $body Dados JSON da requisição
     */
    public static function dispatch($method, $path, $body = []) {
        // Normalizar método e caminho
        $method = strtoupper($method);
        $path = "/" . trim($path, "/");

        // Remover prefixo /v1 se presente
        if (strpos($path, "/v1") === 0) {
            $path = "/" . trim(substr($path, 3), "/");
        }

        // Aplicar Rate Limiting defensivo em todos os endpoints
        RateLimit::handle(180, 60);

        // ─────────────────────────────────────────────────────────
        // 1. SAÚDE E ESTADO DO SERVIÇO
        // ─────────────────────────────────────────────────────────
        if ($method === "GET" && $path === "/health") {
            JsonResponse::success([
                "service" => "sellex-integrations",
                "version" => "v1",
                "status" => "ok"
            ]);
        }

        if ($method === "GET" && $path === "/ready") {
            $db = IntegrationsDatabase::getConnection();
            $dbStatus = $db ? "connected" : "disconnected";
            JsonResponse::success([
                "service" => "sellex-integrations",
                "version" => "v1",
                "database" => $dbStatus,
                "status" => ($dbStatus === "connected") ? "ready" : "degraded"
            ]);
        }

        // ─────────────────────────────────────────────────────────
        // 2. LIGAÇÃO E AUTORIZAÇÃO DE CONTAS META
        // ─────────────────────────────────────────────────────────
        if ($method === "GET" && $path === "/meta/oauth/start") {
            MetaOAuthController::start();
        }

        if ($method === "GET" && $path === "/meta/oauth/callback") {
            MetaOAuthController::callback();
        }

        if ($method === "GET" && $path === "/meta/connections") {
            MetaOAuthController::listConnections();
        }

        if ($method === "POST" && $path === "/meta/connections") {
            MetaOAuthController::saveConnection($body);
        }

        if ($method === "POST" && $path === "/meta/test-ping") {
            MetaOAuthController::testPing($body);
        }

        if ($method === "DELETE" && preg_match('#^/meta/connections/([0-9]+)$#', $path, $matches)) {
            MetaOAuthController::deleteConnection($matches[1]);
        }

        // ─────────────────────────────────────────────────────────
        // 3. WEBHOOKS DA META
        // ─────────────────────────────────────────────────────────
        if ($method === "GET" && $path === "/webhooks/meta") {
            MetaWebhookController::verify();
        }

        if ($method === "POST" && $path === "/webhooks/meta") {
            MetaWebhookController::receive();
        }

        // ─────────────────────────────────────────────────────────
        // 4. ENDPOINTS PROTEGIDOS PARA O N8N
        // ─────────────────────────────────────────────────────────
        if (strpos($path, "/n8n") === 0) {
            // Exige credencial de serviço dedicada do n8n
            AuthenticateN8n::handle();

            // POST /n8n/events
            if ($method === "POST" && $path === "/n8n/events") {
                N8nController::receiveEvent($body);
            }

            // POST /n8n/events/{event_id}/result
            if ($method === "POST" && preg_match('#^/n8n/events/([a-zA-Z0-9_\-]+)/result$#', $path, $matches)) {
                N8nController::recordResult($matches[1], $body);
            }

            // GET /n8n/stores/{store_id}/context
            if ($method === "GET" && preg_match('#^/n8n/stores/([0-9]+)/context$#', $path, $matches)) {
                N8nController::getStoreContext($matches[1]);
            }

            // GET /n8n/stores/{store_id}/products
            if ($method === "GET" && preg_match('#^/n8n/stores/([0-9]+)/products$#', $path, $matches)) {
                N8nController::getStoreProducts($matches[1]);
            }

            // GET /n8n/stores/{store_id}/customers/{customer_id}
            if ($method === "GET" && preg_match('#^/n8n/stores/([0-9]+)/customers/([^/]+)$#', $path, $matches)) {
                N8nController::getStoreCustomer($matches[1], $matches[2]);
            }

            // POST /n8n/actions/send-message
            if ($method === "POST" && $path === "/n8n/actions/send-message") {
                N8nController::sendMessage($body);
            }

            // GET /n8n/events/{event_id}
            if ($method === "GET" && preg_match('#^/n8n/events/([a-zA-Z0-9_\-]+)$#', $path, $matches)) {
                N8nController::getEvent($matches[1]);
            }
        }

        // ─────────────────────────────────────────────────────────
        // 5. ROTINA DE REENTREGA CONTROLADA (Cron)
        // ─────────────────────────────────────────────────────────
        if (($method === "GET" || $method === "POST") && $path === "/cron/retry") {
            AuthenticateN8n::handle();
            ob_start();
            require __DIR__ . "/../cron/retry.php";
            $output = ob_get_clean();
            $data = json_decode($output, true) ?: ["raw" => $output];
            JsonResponse::success($data);
        }

        // Rota não encontrada
        JsonResponse::notFound("Endpoint '{$method} {$path}' não encontrado no módulo de integrações.");
    }
}
