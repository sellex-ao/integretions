<?php
// ─────────────────────────────────────────────────────────────
// SERVIÇO DE COMUNICAÇÃO COM O N8N (N8nService.php)
// Encaminhamento de Eventos e Envio de Mensagens Oficiais
// Localização: /api/integrations/services/N8nService.php
// ─────────────────────────────────────────────────────────────

require_once __DIR__ . "/../config/secrets.php";
require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../services/SocialEventService.php";
require_once __DIR__ . "/../utils/Idempotency.php";

class N8nService {

    /**
     * Encaminha um evento normalizado para o webhook do n8n Cloud
     * Segue estritamente o Workflow 1: se n8n aceitou -> atualiza estado; se não -> agenda nova tentativa
     */
    public static function dispatch($event) {
        $webhookUrl = IntegrationsSecrets::getN8nWebhookUrl();
        $eventId = $event["event_id"] ?? "";

        if (empty($webhookUrl)) {
            SocialEventService::updateStatus($eventId, "pending", "N8N_WEBHOOK_URL não configurado");
            return ["success" => false, "status" => "pending", "error" => "URL de webhook n8n não configurada"];
        }

        $payloadJson = json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $ch = curl_init($webhookUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payloadJson);
        curl_setopt($ch, CURLOPT_TIMEOUT, 6); // Timeout curto para não bloquear requisição Meta
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Content-Type: application/json",
            "X-Sellex-Service: integrations",
            "X-Sellex-Event-Id: {$eventId}",
            "Authorization: Bearer " . IntegrationsSecrets::getN8nServiceToken()
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if (!$curlError && ($httpCode === 200 || $httpCode === 202)) {
            // n8n aceitou o evento
            SocialEventService::updateStatus($eventId, "forwarded");
            return [
                "success" => true,
                "status" => "forwarded",
                "http_code" => $httpCode
            ];
        } else {
            // n8n falhou ou indisponível: manter 'pending' para re-tentativa pelo Cron
            $errorMsg = $curlError ?: "n8n devolveu HTTP {$httpCode}";
            SocialEventService::incrementAttempt($eventId, $errorMsg);
            return [
                "success" => false,
                "status" => "pending",
                "error" => $errorMsg
            ];
        }
    }

    /**
     * Encaminha múltiplos eventos para o n8n
     */
    public static function dispatchEvents(array $events) {
        $count = 0;
        foreach ($events as $evt) {
            $res = self::dispatch($evt);
            if (!empty($res["success"])) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Envia uma mensagem oficial através da API da Meta a pedido do n8n
     * Segue estritamente o Workflow 2: valida regras e envia pelo canal autorizado
     */
    public static function sendOutboundMessage($storeId, $connectionId, $recipientExternalId, $messageText, $idempotencyKey) {
        $db = IntegrationsDatabase::getConnection();

        // 1. Checar idempotência
        $existing = Idempotency::checkOutbound($idempotencyKey);
        if ($existing) {
            return [
                "idempotent" => true,
                "status" => $existing["status"],
                "external_message_id" => $existing["external_message_id"]
            ];
        }

        // 2. Validar se a loja possui esta conexão ativa
        $stmt = $db->prepare("
            SELECT * FROM social_connections 
            WHERE id = ? AND store_id = ? AND connection_status = 'connected' 
            LIMIT 1
        ");
        $stmt->execute([(int)$connectionId, (int)$storeId]);
        $conn = $stmt->fetch();

        if (!$conn) {
            throw new Exception("Conexão social não encontrada, inativa ou não pertence à loja especificada.");
        }

        // 3. Localizar conversa
        $stmtConv = $db->prepare("
            SELECT id FROM social_conversations 
            WHERE store_id = ? AND connection_id = ? AND external_customer_id = ? 
            LIMIT 1
        ");
        $stmtConv->execute([(int)$storeId, (int)$connectionId, (string)$recipientExternalId]);
        $conv = $stmtConv->fetch();
        $conversationId = $conv ? (int)$conv["id"] : null;

        // 4. Registar intenção de envio com chave de idempotência
        $stmtInsert = $db->prepare("
            INSERT INTO social_outbound_messages 
                (store_id, connection_id, conversation_id, idempotency_key, recipient_external_id, message_text, status, created_at)
            VALUES 
                (?, ?, ?, ?, ?, ?, 'queued', CURRENT_TIMESTAMP)
        ");
        $stmtInsert->execute([
            (int)$storeId,
            (int)$connectionId,
            $conversationId,
            $idempotencyKey,
            $recipientExternalId,
            $messageText
        ]);
        $outboundId = (int)$db->lastInsertId();

        // 5. Decifrar token de acesso da página/conta
        $plainToken = IntegrationsSecrets::decryptToken($conn["access_token_encrypted"]);
        if (empty($plainToken)) {
            $stmtFail = $db->prepare("UPDATE social_outbound_messages SET status = 'failed', error_code = 'TOKEN_DECRYPT_FAIL' WHERE id = ?");
            $stmtFail->execute([$outboundId]);
            throw new Exception("Não foi possível decifrar as credenciais da conta social.");
        }

        // 6. Enviar mensagem via Meta Graph API Oficial
        $url = "https://graph.facebook.com/v19.0/me/messages?access_token=" . urlencode($plainToken);
        $payload = [
            "recipient" => ["id" => $recipientExternalId],
            "message" => ["text" => $messageText]
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
        $rawRes = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        $resData = json_decode($rawRes, true) ?: [];

        if (!$curlError && $httpCode === 200 && !empty($resData["message_id"])) {
            $extMsgId = (string)$resData["message_id"];
            $stmtSent = $db->prepare("
                UPDATE social_outbound_messages 
                SET status = 'sent', external_message_id = ?, sent_at = CURRENT_TIMESTAMP 
                WHERE id = ?
            ");
            $stmtSent->execute([$extMsgId, $outboundId]);

            return [
                "success" => true,
                "status" => "sent",
                "external_message_id" => $extMsgId,
                "idempotency_key" => $idempotencyKey
            ];
        } else {
            $errCode = $resData["error"]["code"] ?? "SEND_FAILED";
            $errMsg = $resData["error"]["message"] ?? ($curlError ?: "Falha no envio HTTP {$httpCode}");

            $stmtErr = $db->prepare("
                UPDATE social_outbound_messages 
                SET status = 'failed', error_code = ?, error_message = ? 
                WHERE id = ?
            ");
            $stmtErr->execute([$errCode, $errMsg, $outboundId]);

            return [
                "success" => false,
                "status" => "failed",
                "error_code" => $errCode,
                "error_message" => $errMsg
            ];
        }
    }
}
