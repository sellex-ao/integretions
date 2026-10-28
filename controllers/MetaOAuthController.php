<?php
// ─────────────────────────────────────────────────────────────
// CONTROLADOR DE AUTORIZAÇÃO META OAUTH (MetaOAuthController.php)
// Conexão e Desconexão de Contas Sociais Protegida por JWT
// Localização: /api/integrations/controllers/MetaOAuthController.php
// ─────────────────────────────────────────────────────────────

require_once __DIR__ . "/../services/MetaOAuthService.php";
require_once __DIR__ . "/../utils/JsonResponse.php";

// Carregar Middleware de Autenticação JWT Oficial da Sellex
if (file_exists(__DIR__ . "/../../api-app/middleware/AuthMiddleware.php")) {
    require_once __DIR__ . "/../../api-app/middleware/AuthMiddleware.php";
}

class MetaOAuthController {

    /**
     * Valida rigorosamente o token JWT da loja autenticada
     */
    private static function getMerchantAuth() {
        if (class_exists("AuthMiddleware")) {
            return AuthMiddleware::authenticate();
        }
        JsonResponse::unauthorized("Sistema de autenticação Sellex indisponível.");
    }

    /**
     * Inicia a autorização da loja para conectar sua conta Meta
     * Protegido por JWT: o store_id é extraído estritamente do token do vendedor
     * GET /meta/oauth/start
     */
    public static function start() {
        $auth = self::getMerchantAuth();
        $storeId = (int)$auth["payload"]["store_id"];
        $storeSlug = (string)($auth["payload"]["store_slug"] ?? "");

        $config = require __DIR__ . "/../config/app.php";
        $defaultCallback = rtrim($config["base_url"], "/") . "/meta/oauth/callback";
        $redirectUri = $_GET["redirect_uri"] ?? $defaultCallback;

        try {
            $authUrl = MetaOAuthService::generateAuthUrl($storeId, $redirectUri, $storeSlug);
            JsonResponse::success([
                "store_id" => $storeId,
                "store_slug" => $storeSlug,
                "authorization_url" => $authUrl,
                "redirect_uri" => $redirectUri
            ]);
        } catch (Exception $e) {
            JsonResponse::serverError("Erro ao gerar URL de autorização Meta: " . $e->getMessage());
        }
    }

    /**
     * Recebe o retorno do fluxo de autorização da Meta
     * O state criptografado garante que a conta Meta só seja associada à loja que iniciou o pedido
     * GET /meta/oauth/callback?code=...&state=...
     */
    public static function callback() {
        $code = $_GET["code"] ?? "";
        $state = $_GET["state"] ?? "";

        if (empty($code) || empty($state)) {
            JsonResponse::error("INVALID_CALLBACK", "Código de autorização ou state ausentes.", 400);
        }

        $stateData = MetaOAuthService::validateState($state);
        if (!$stateData || empty($stateData["store_id"])) {
            JsonResponse::forbidden("Parâmetro state inválido, expirado ou adulterado. Possível ataque CSRF.");
        }

        $storeId = (int)$stateData["store_id"];

        $config = require __DIR__ . "/../config/app.php";
        $redirectUri = rtrim($config["base_url"], "/") . "/meta/oauth/callback";

        try {
            $result = MetaOAuthService::handleCallback($storeId, $code, $redirectUri);

            // Redirecionar para o painel do vendedor caso a resposta venha do navegador
            $isBrowser = strpos($_SERVER["HTTP_ACCEPT"] ?? "", "text/html") !== false;
            if ($isBrowser) {
                $sellerUrl = "https://app.sellex.ao/seller/?tab=integrations&status=connected&accounts=" . $result["connected_count"];
                header("Location: " . $sellerUrl);
                exit;
            }

            JsonResponse::success($result);
        } catch (Exception $e) {
            JsonResponse::error("OAUTH_FAILED", $e->getMessage(), 400);
        }
    }

    /**
     * Lista as conexões sociais ativas da loja autenticada
     * Protegido por JWT: não expõe dados de outras lojas
     * GET /meta/connections
     */
    public static function listConnections() {
        $auth = self::getMerchantAuth();
        $storeId = (int)$auth["payload"]["store_id"];

        $connections = MetaOAuthService::getStoreConnections($storeId);
        JsonResponse::success([
            "store_id" => $storeId,
            "total" => count($connections),
            "connections" => $connections
        ]);
    }

    /**
     * Guarda ou atualiza a ligação da página informada pelo lojista
     * POST /meta/connections
     */
    public static function saveConnection($body = []) {
        $auth = self::getMerchantAuth();
        $storeId = (int)$auth["payload"]["store_id"];

        $provider = $body["provider"] ?? "facebook";
        $externalAccountId = $body["external_account_id"] ?? ($body["page_id"] ?? "");
        $accountName = $body["account_name"] ?? ($body["page_name"] ?? "");
        $instagramUsername = $body["instagram_username"] ?? null;

        try {
            $conn = MetaOAuthService::saveManualConnection($storeId, $provider, $externalAccountId, $accountName, $instagramUsername);
            JsonResponse::success([
                "message" => "Página vinculada com sucesso à sua loja!",
                "connection" => $conn
            ]);
        } catch (Exception $e) {
            JsonResponse::error("SAVE_CONNECTION_FAILED", $e->getMessage(), 400);
        }
    }

    /**
     * Dispara um teste em tempo real simulando cliente para verificar n8n e webhooks
     * POST /meta/test-ping
     */
    public static function testPing($body = []) {
        $auth = self::getMerchantAuth();
        $storeId = (int)$auth["payload"]["store_id"];
        $connectionId = !empty($body["connection_id"]) ? (int)$body["connection_id"] : null;
        $testMessage = !empty($body["message"]) ? (string)$body["message"] : null;

        try {
            $result = MetaOAuthService::sendTestPing($storeId, $connectionId, $testMessage);
            JsonResponse::success($result);
        } catch (Throwable $e) {
            JsonResponse::error("TEST_PING_FAILED", $e->getMessage(), 400);
        }
    }

    /**
     * Desconecta uma ligação social pertencente à loja autenticada
     * DELETE /meta/connections/{id}
     */
    public static function deleteConnection($connectionId) {
        $auth = self::getMerchantAuth();
        $storeId = (int)$auth["payload"]["store_id"];

        $disconnected = MetaOAuthService::disconnect($storeId, $connectionId);
        if ($disconnected) {
            JsonResponse::success([
                "connection_id" => (int)$connectionId,
                "store_id" => $storeId,
                "status" => "disconnected"
            ]);
        } else {
            JsonResponse::notFound("Conexão não encontrada, já desconectada ou não pertence à sua loja.");
        }
    }
}
