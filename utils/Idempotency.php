<?php
// ─────────────────────────────────────────────────────────────
// CONTROLO DE IDEMPOTÊNCIA E DEDUPLICAÇÃO (Idempotency.php)
// Evita Repetições e Execuções Duplicadas de Eventos e Mensagens
// Localização: /api/integrations/utils/Idempotency.php
// ─────────────────────────────────────────────────────────────

require_once __DIR__ . "/../config/database.php";

class Idempotency {

    /**
     * Verifica se um evento recebido já foi registado anteriormente
     * Devolve o registo existente caso seja duplicado
     */
    public static function checkEvent($eventId, $externalEventId = null) {
        $db = IntegrationsDatabase::getConnection();
        if (!$db) return null;

        try {
            // 1. Checar pelo ID interno do evento
            $stmt = $db->prepare("SELECT * FROM social_events WHERE event_id = ? LIMIT 1");
            $stmt->execute([$eventId]);
            $existing = $stmt->fetch();
            if ($existing) return $existing;

            // 2. Checar pelo ID externo da Meta (se fornecido)
            if (!empty($externalEventId)) {
                $stmtExt = $db->prepare("SELECT * FROM social_events WHERE external_event_id = ? LIMIT 1");
                $stmtExt->execute([$externalEventId]);
                $existingExt = $stmtExt->fetch();
                if ($existingExt) return $existingExt;
            }
        } catch (Exception $e) {
            error_log("[IDEMPOTENCY CHECK ERROR] " . $e->getMessage());
        }

        return null;
    }

    /**
     * Verifica se uma mensagem de saída com a mesma chave de idempotência já foi processada
     */
    public static function checkOutbound($idempotencyKey) {
        if (empty($idempotencyKey)) return null;

        $db = IntegrationsDatabase::getConnection();
        if (!$db) return null;

        try {
            $stmt = $db->prepare("SELECT * FROM social_outbound_messages WHERE idempotency_key = ? LIMIT 1");
            $stmt->execute([trim((string)$idempotencyKey)]);
            return $stmt->fetch() ?: null;
        } catch (Exception $e) {
            error_log("[IDEMPOTENCY OUTBOUND CHECK ERROR] " . $e->getMessage());
            return null;
        }
    }

    /**
     * Gera identificador único de evento no formato padrão evt_...
     */
    public static function generateEventId() {
        return "evt_" . bin2hex(random_bytes(10));
    }

    /**
     * Gera identificador único para mensagens enviadas
     */
    public static function generateMessageId() {
        return "msg_out_" . bin2hex(random_bytes(10));
    }
}
