<?php
// ─────────────────────────────────────────────────────────────
// SERVIÇO DE AUTORIZAÇÃO META OAUTH (MetaOAuthService.php)
// Conexão Segura de Páginas do Facebook e Contas Instagram
// Localização: /api/integrations/services/MetaOAuthService.php
// ─────────────────────────────────────────────────────────────

require_once __DIR__ . "/../config/secrets.php";
require_once __DIR__ . "/../config/database.php";

class MetaOAuthService {

    const META_GRAPH_VERSION = "v19.0";
    const META_OAUTH_DIALOG = "https://www.facebook.com/v19.0/dialog/oauth";
    const META_GRAPH_API = "https://graph.facebook.com/v19.0";

    /**
     * Gera URL de autorização da Meta com parâmetro state assinado contra CSRF
     * Vinculado estritamente à loja e ao slug da sessão autenticada
     */
    public static function generateAuthUrl($storeId, $redirectUri, $storeSlug = "") {
        $appId = IntegrationsSecrets::getMetaAppId();
        $secret = IntegrationsSecrets::getMetaAppSecret();

        $timestamp = time();
        $nonce = bin2hex(random_bytes(8));
        $data = "{$storeId}:{$timestamp}:{$nonce}:{$storeSlug}";
        $signature = hash_hmac("sha256", $data, $secret);
        $state = base64_encode("{$data}:{$signature}");

        $scopes = [
            "pages_show_list",
            "pages_read_engagement",
            "pages_manage_metadata",
            "pages_messaging",
            "instagram_basic",
            "instagram_manage_messages"
        ];

        $params = http_build_query([
            "client_id" => $appId,
            "redirect_uri" => $redirectUri,
            "state" => $state,
            "scope" => implode(",", $scopes),
            "response_type" => "code"
        ]);

        return self::META_OAUTH_DIALOG . "?" . $params;
    }

    /**
     * Valida o parâmetro state recebido no retorno da Meta
     * Retorna array com store_id e store_slug se válido, ou null
     */
    public static function validateState($state) {
        if (empty($state)) return null;

        $decoded = base64_decode($state, true);
        if (!$decoded) return null;

        $parts = explode(":", $decoded);
        if (count($parts) !== 5) return null;

        list($storeId, $timestamp, $nonce, $storeSlug, $signature) = $parts;

        // Janela de validade: 20 minutos
        if (time() - (int)$timestamp > 1200) {
            return null;
        }

        $secret = IntegrationsSecrets::getMetaAppSecret();
        $expectedData = "{$storeId}:{$timestamp}:{$nonce}:{$storeSlug}";
        $expectedSignature = hash_hmac("sha256", $expectedData, $secret);

        if (!hash_equals($expectedSignature, $signature)) {
            return null;
        }

        return [
            "store_id" => (int)$storeId,
            "store_slug" => $storeSlug
        ];
    }

    /**
     * Conclui a troca do código de autorização por tokens de página e Instagram
     */
    public static function handleCallback($storeId, $code, $redirectUri) {
        $appId = IntegrationsSecrets::getMetaAppId();
        $secret = IntegrationsSecrets::getMetaAppSecret();

        // 1. Trocar código por User Access Token inicial
        $tokenUrl = self::META_GRAPH_API . "/oauth/access_token?" . http_build_query([
            "client_id" => $appId,
            "client_secret" => $secret,
            "redirect_uri" => $redirectUri,
            "code" => $code
        ]);

        $tokenResponse = self::httpGetJson($tokenUrl);
        if (empty($tokenResponse["access_token"])) {
            throw new Exception("Falha ao obter token de acesso inicial da Meta: " . ($tokenResponse["error"]["message"] ?? "Erro desconhecido"));
        }

        $userAccessToken = $tokenResponse["access_token"];

        // 2. Trocar por token de utilizador de longa duração (60 dias)
        $longLivedUrl = self::META_GRAPH_API . "/oauth/access_token?" . http_build_query([
            "grant_type" => "fb_exchange_token",
            "client_id" => $appId,
            "client_secret" => $secret,
            "fb_exchange_token" => $userAccessToken
        ]);
        $longLivedRes = self::httpGetJson($longLivedUrl);
        $finalUserToken = $longLivedRes["access_token"] ?? $userAccessToken;

        // 3. Obter páginas geridas e contas de Instagram vinculadas
        $accountsUrl = self::META_GRAPH_API . "/me/accounts?" . http_build_query([
            "access_token" => $finalUserToken,
            "fields" => "id,name,access_token,instagram_business_account{id,username}"
        ]);
        $accountsData = self::httpGetJson($accountsUrl);

        $connectedAccounts = [];
        $db = IntegrationsDatabase::getConnection();

        if (!empty($accountsData["data"])) {
            foreach ($accountsData["data"] as $page) {
                $pageId = (string)$page["id"];
                $pageName = (string)$page["name"];
                $pageToken = (string)$page["access_token"];

                // A. Registar Conexão do Facebook com o Page Access Token
                self::upsertConnection($db, $storeId, "facebook", $pageId, $pageName, $pageToken, "pages_messaging,pages_show_list");
                $connectedAccounts[] = [
                    "provider" => "facebook",
                    "account_id" => $pageId,
                    "name" => $pageName
                ];

                // B. Se houver conta Instagram comercial vinculada
                if (!empty($page["instagram_business_account"])) {
                    $ig = $page["instagram_business_account"];
                    $igId = (string)$ig["id"];
                    $igUsername = (string)($ig["username"] ?? $pageName);

                    // O Page Access Token com escopos adequados é utilizado para gerir a conta Instagram vinculada
                    self::upsertConnection($db, $storeId, "instagram", $igId, $igUsername, $pageToken, "instagram_manage_messages,instagram_basic");
                    $connectedAccounts[] = [
                        "provider" => "instagram",
                        "account_id" => $igId,
                        "name" => $igUsername
                    ];
                }
            }
        }

        return [
            "success" => true,
            "store_id" => $storeId,
            "connected_count" => count($connectedAccounts),
            "accounts" => $connectedAccounts
        ];
    }

    private static function upsertConnection($db, $storeId, $provider, $externalAccountId, $accountName, $plainToken, $scopes) {
        if (!$db) return;
        $encryptedToken = IntegrationsSecrets::encryptToken($plainToken);
        $expiresAt = date("Y-m-d H:i:s", strtotime("+60 days"));

        $stmt = $db->prepare("
            INSERT INTO social_connections 
                (store_id, provider, external_account_id, account_name, connection_status, granted_scopes, access_token_encrypted, token_expires_at)
            VALUES 
                (?, ?, ?, ?, 'connected', ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                account_name = VALUES(account_name),
                connection_status = 'connected',
                granted_scopes = VALUES(granted_scopes),
                access_token_encrypted = VALUES(access_token_encrypted),
                token_expires_at = VALUES(token_expires_at),
                updated_at = CURRENT_TIMESTAMP
        ");

        $stmt->execute([
            $storeId,
            $provider,
            $externalAccountId,
            $accountName,
            $scopes,
            $encryptedToken,
            $expiresAt
        ]);
    }

    public static function getStoreConnections($storeId) {
        $db = IntegrationsDatabase::getConnection();
        if (!$db) return [];

        try {
            $stmt = $db->prepare("
                SELECT id, store_id, provider, external_account_id, account_name, connection_status, granted_scopes, token_expires_at, created_at, updated_at
                FROM social_connections
                WHERE store_id = ? AND connection_status != 'disconnected'
                ORDER BY id DESC
            ");
            $stmt->execute([(int)$storeId]);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log("[GET STORE CONNECTIONS ERROR] " . $e->getMessage());
            return [];
        }
    }

    public static function disconnect($storeId, $connectionId) {
        $db = IntegrationsDatabase::getConnection();
        if (!$db) return false;

        try {
            $stmt = $db->prepare("
                UPDATE social_connections 
                SET connection_status = 'disconnected', updated_at = CURRENT_TIMESTAMP 
                WHERE id = ? AND store_id = ?
            ");
            $stmt->execute([(int)$connectionId, (int)$storeId]);
            return $stmt->rowCount() > 0;
        } catch (Exception $e) {
            error_log("[DISCONNECT ERROR] " . $e->getMessage());
            return false;
        }
    }

    /**
     * Guarda ou atualiza uma ligação social a partir do painel do lojista
     */
    public static function saveManualConnection($storeId, $provider, $externalAccountId, $accountName, $instagramUsername = null) {
        $db = IntegrationsDatabase::getConnection();
        if (!$db) {
            throw new Exception("Falha de conexão com a base de dados.");
        }

        $provider = in_array(strtolower($provider), ["facebook", "instagram", "whatsapp"]) ? strtolower($provider) : "facebook";
        $externalAccountId = trim((string)$externalAccountId);
        $accountName = trim((string)$accountName);

        if (empty($externalAccountId)) {
            throw new Exception("O ID da Página ou Conta externa é obrigatório.");
        }

        if (empty($accountName)) {
            $accountName = ($provider === "facebook") ? "Página Facebook" : "Conta Instagram";
        }

        $dummyToken = "conn_token_" . bin2hex(random_bytes(16));
        $encryptedToken = IntegrationsSecrets::encryptToken($dummyToken);
        $scopes = "pages_messaging,pages_show_list,pages_manage_metadata";

        // Verificar se já existe conexão desta loja e conta
        $stmt = $db->prepare("
            SELECT id FROM social_connections 
            WHERE store_id = ? AND provider = ? AND external_account_id = ? 
            LIMIT 1
        ");
        $stmt->execute([(int)$storeId, $provider, $externalAccountId]);
        $existing = $stmt->fetch();

        if ($existing) {
            $connId = (int)$existing["id"];
            $stmtUp = $db->prepare("
                UPDATE social_connections 
                SET account_name = ?, connection_status = 'connected', updated_at = CURRENT_TIMESTAMP 
                WHERE id = ?
            ");
            $stmtUp->execute([$accountName, $connId]);
        } else {
            $stmtIns = $db->prepare("
                INSERT INTO social_connections 
                    (store_id, provider, external_account_id, account_name, connection_status, granted_scopes, access_token_encrypted)
                VALUES 
                    (?, ?, ?, ?, 'connected', ?, ?)
            ");
            $stmtIns->execute([
                (int)$storeId,
                $provider,
                $externalAccountId,
                $accountName,
                $scopes,
                $encryptedToken
            ]);
            $connId = (int)$db->lastInsertId();
        }

        // Se informou nome de utilizador do Instagram, vincula também conta Instagram
        if (!empty($instagramUsername) && $provider === "facebook") {
            $igAccountName = ltrim(trim($instagramUsername), "@");
            $igExtId = "ig_" . $externalAccountId;
            $stmtIg = $db->prepare("
                SELECT id FROM social_connections 
                WHERE store_id = ? AND provider = 'instagram' 
                LIMIT 1
            ");
            $stmtIg->execute([(int)$storeId]);
            $existingIg = $stmtIg->fetch();
            if ($existingIg) {
                $db->prepare("UPDATE social_connections SET external_account_id = ?, account_name = ?, connection_status = 'connected', updated_at = CURRENT_TIMESTAMP WHERE id = ?")
                   ->execute([$igExtId, "@" . $igAccountName, (int)$existingIg["id"]]);
            } else {
                $db->prepare("INSERT INTO social_connections (store_id, provider, external_account_id, account_name, connection_status, granted_scopes, access_token_encrypted) VALUES (?, 'instagram', ?, ?, 'connected', 'instagram_basic,instagram_manage_messages', ?)")
                   ->execute([(int)$storeId, $igExtId, "@" . $igAccountName, $encryptedToken]);
            }
        }

        return [
            "id" => $connId,
            "store_id" => (int)$storeId,
            "provider" => $provider,
            "external_account_id" => $externalAccountId,
            "account_name" => $accountName,
            "connection_status" => "connected"
        ];
    }

    /**
     * Dispara um evento simulado em tempo real para testar a comunicação loja -> Sellex -> n8n
     */
    public static function sendTestPing($storeId, $connectionId = null, $testMessage = null) {
        $db = IntegrationsDatabase::getConnection();
        if (!$db) {
            throw new Exception("Base de dados inacessível.");
        }

        if ($connectionId) {
            $stmt = $db->prepare("SELECT * FROM social_connections WHERE id = ? AND store_id = ? AND connection_status = 'connected' LIMIT 1");
            $stmt->execute([(int)$connectionId, (int)$storeId]);
        } else {
            $stmt = $db->prepare("SELECT * FROM social_connections WHERE store_id = ? AND connection_status = 'connected' ORDER BY id DESC LIMIT 1");
            $stmt->execute([(int)$storeId]);
        }
        $conn = $stmt->fetch();

        if (!$conn) {
            throw new Exception("Nenhuma conexão ativa encontrada para esta loja. Conecte primeiro a sua Página do Facebook.");
        }

        $provider = $conn["provider"];
        $externalAccountId = $conn["external_account_id"];
        $connId = (int)$conn["id"];

        require_once __DIR__ . "/SocialEventService.php";
        require_once __DIR__ . "/N8nService.php";
        require_once __DIR__ . "/../utils/Idempotency.php";

        $eventId = Idempotency::generateEventId();
        $testMsgText = !empty($testMessage) ? trim($testMessage) : "Olá! Gostaria de saber os preços e consultar o catálogo de produtos da loja.";
        $senderId = "test_user_" . rand(1000, 9999);
        $nowIso = date("c");

        $normalizedEvent = [
            "event_id" => $eventId,
            "event_type" => "social.message.received",
            "version" => "1.0",
            "occurred_at" => $nowIso,
            "store_id" => (int)$storeId,
            "channel" => $provider,
            "connection_id" => $connId,
            "external_account_id" => $externalAccountId,
            "conversation_id" => "conv_{$externalAccountId}_{$senderId}",
            "customer" => [
                "external_id" => $senderId,
                "display_name" => "Cliente Teste (" . strtoupper($provider) . ")"
            ],
            "message" => [
                "external_message_id" => "mid_test_" . bin2hex(random_bytes(6)),
                "text" => $testMsgText
            ],
            "metadata" => [
                "source" => "seller_dashboard_test",
                "is_simulation" => true
            ]
        ];

        // 1. Gravar em social_events e social_conversations
        SocialEventService::recordEvent($normalizedEvent);

        // 2. Disparar em tempo real para o n8n
        $n8nDelivered = false;
        try {
            $dispatchRes = N8nService::dispatch($normalizedEvent);
            $n8nDelivered = !empty($dispatchRes["success"]);
        } catch (Throwable $e) {
            error_log("[TEST PING N8N DISPATCH ERROR] " . $e->getMessage());
        }

        return [
            "success" => true,
            "event_id" => $eventId,
            "store_id" => (int)$storeId,
            "connection_id" => $connId,
            "provider" => $provider,
            "account_name" => $conn["account_name"],
            "test_message" => $testMsgText,
            "n8n_delivered" => $n8nDelivered,
            "status" => "forwarded",
            "message" => "Evento de teste em tempo real disparado e gravado com sucesso!"
        ];
    }

    private static function httpGetJson($url) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        $response = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err) {
            throw new Exception("Falha na chamada cURL à Meta: " . $err);
        }

        return json_decode($response, true) ?: [];
    }
}
