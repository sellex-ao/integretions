<?php
// ─────────────────────────────────────────────────────────────
// SERVIÇO DE EVENTOS SOCIAIS (SocialEventService.php)
// Normalização, Registo Idempotente, Deduplicação e Conversas
// Localização: /api/integrations/services/SocialEventService.php
// ─────────────────────────────────────────────────────────────

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../utils/Idempotency.php";

class SocialEventService {

    /**
     * Regista um evento normalizado na base de dados de forma idempotente
     */
    public static function recordEvent($event) {
        $db = IntegrationsDatabase::getConnection();

        $eventId = $event["event_id"] ?? Idempotency::generateEventId();
        $externalEventId = $event["message"]["external_message_id"] ?? null;

        // 1. Verificação de Idempotência
        $existing = Idempotency::checkEvent($eventId, $externalEventId);
        if ($existing) {
            return [
                "duplicate" => true,
                "event_id" => $existing["event_id"],
                "status" => $existing["processing_status"]
            ];
        }

        $storeId = (int)($event["store_id"] ?? 0);
        $connectionId = !empty($event["connection_id"]) ? (int)$event["connection_id"] : null;
        $provider = $event["channel"] ?? ($event["provider"] ?? "facebook");
        $eventType = $event["event_type"] ?? "social.message.received";
        $payloadJson = json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        // Se storeId não veio explicitamente, tentar localizar pela página/conta externa
        if ($storeId <= 0 && $db) {
            $externalAccount = $event["external_account_id"] ?? ($event["page_id"] ?? null);
            if ($externalAccount) {
                try {
                    $stLookup = $db->prepare("
                        SELECT id, store_id, provider 
                        FROM social_connections 
                        WHERE external_account_id = ? AND connection_status = 'connected' 
                        LIMIT 1
                    ");
                    $stLookup->execute([(string)$externalAccount]);
                    $found = $stLookup->fetch();
                    if ($found) {
                        $storeId = (int)$found["store_id"];
                        $connectionId = (int)$found["id"];
                        if (empty($event["channel"])) {
                            $provider = $found["provider"];
                        }
                    }
                } catch (Exception $e) {
                    error_log("[SOCIAL EVENT AUTO LOOKUP ERROR] " . $e->getMessage());
                }
            }
        }

        // Se storeId for desconhecido (loja ainda não vinculou página), salvar como unlinked_store
        $status = ($storeId > 0) ? "pending" : "unlinked_store";

        if (!$db) {
            return [
                "duplicate" => false,
                "event_id" => $eventId,
                "status" => $status
            ];
        }

        try {
            // 2. Inserir na tabela social_events
            $stmt = $db->prepare("
                INSERT INTO social_events 
                    (event_id, store_id, connection_id, provider, event_type, external_event_id, payload_json, processing_status, attempt_count, received_at)
                VALUES 
                    (?, ?, ?, ?, ?, ?, ?, ?, 0, CURRENT_TIMESTAMP)
            ");
            $stmt->execute([
                $eventId,
                $storeId,
                $connectionId,
                $provider,
                $eventType,
                $externalEventId,
                $payloadJson,
                $status
            ]);

            // 3. Atualizar ou Criar Conversa Social se storeId e connectionId forem válidos
            if ($storeId > 0 && $connectionId > 0 && !empty($event["conversation_id"])) {
                self::upsertConversation($db, $storeId, $connectionId, $provider, $event);
            }
        } catch (Throwable $e) {
            error_log("[SOCIAL EVENT RECORD ERROR] " . $e->getMessage());
        }

        return [
            "duplicate" => false,
            "event_id" => $eventId,
            "status" => $status
        ];
    }

    /**
     * Organiza ou atualiza a conversa com o cliente na tabela social_conversations
     */
    private static function upsertConversation($db, $storeId, $connectionId, $provider, $event) {
        $externalConvId = (string)($event["conversation_id"] ?? "");
        $externalCustId = (string)($event["customer"]["external_id"] ?? "");
        $customerName = (string)($event["customer"]["display_name"] ?? "Cliente");

        $stmt = $db->prepare("
            INSERT INTO social_conversations 
                (store_id, connection_id, provider, external_conversation_id, external_customer_id, customer_name, last_message_at, conversation_status)
            VALUES 
                (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, 'open')
            ON DUPLICATE KEY UPDATE
                last_message_at = CURRENT_TIMESTAMP,
                customer_name = IF(VALUES(customer_name) != '', VALUES(customer_name), customer_name),
                updated_at = CURRENT_TIMESTAMP
        ");

        try {
            $stmt->execute([
                $storeId,
                $connectionId,
                $provider,
                $externalConvId,
                $externalCustId,
                $customerName
            ]);
        } catch (Throwable $e) {
            error_log("[UPSERT CONVERSATION ERROR] " . $e->getMessage());
        }
    }

    /**
     * Atualiza o estado de processamento de um evento
     */
    public static function updateStatus($eventId, $status, $error = null) {
        $db = IntegrationsDatabase::getConnection();
        if (!$db) return false;

        $processedAt = in_array($status, ["processed", "forwarded"]) ? date("Y-m-d H:i:s") : null;

        try {
            $stmt = $db->prepare("
                UPDATE social_events 
                SET processing_status = ?, last_error = ?, processed_at = COALESCE(?, processed_at)
                WHERE event_id = ?
            ");
            $stmt->execute([$status, $error, $processedAt, $eventId]);
            return $stmt->rowCount() > 0;
        } catch (Exception $e) {
            error_log("[SOCIAL EVENT UPDATE ERROR] " . $e->getMessage());
            return false;
        }
    }

    /**
     * Incrementa o contador de tentativas de entrega de um evento
     */
    public static function incrementAttempt($eventId, $error = null) {
        $db = IntegrationsDatabase::getConnection();
        if (!$db) return;

        try {
            $stmt = $db->prepare("
                UPDATE social_events 
                SET attempt_count = attempt_count + 1, last_error = ?
                WHERE event_id = ?
            ");
            $stmt->execute([$error, $eventId]);
        } catch (Exception $e) {
            error_log("[SOCIAL EVENT INCREMENT ERROR] " . $e->getMessage());
        }
    }

    /**
     * Consulta o estado e dados de um evento
     */
    public static function getEvent($eventId) {
        $db = IntegrationsDatabase::getConnection();
        if (!$db) return null;

        try {
            $stmt = $db->prepare("SELECT * FROM social_events WHERE event_id = ? LIMIT 1");
            $stmt->execute([$eventId]);
            $row = $stmt->fetch();

            if ($row) {
                $row["payload_json"] = json_decode($row["payload_json"], true);
            }
            return $row ?: null;
        } catch (Exception $e) {
            error_log("[SOCIAL EVENT GET ERROR] " . $e->getMessage());
            return null;
        }
    }
}
