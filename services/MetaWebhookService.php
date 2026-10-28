<?php
// ─────────────────────────────────────────────────────────────
// SERVIÇO DE WEBHOOKS META (MetaWebhookService.php)
// Interpretação, Validação e Normalização de Eventos
// Localização: /api/integrations/services/MetaWebhookService.php
// ─────────────────────────────────────────────────────────────

require_once __DIR__ . "/../config/secrets.php";
require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../utils/Idempotency.php";

class MetaWebhookService {

    /**
     * Validação do desafio de verificação inicial enviado pela Meta (GET)
     */
    public static function verifyChallenge($mode, $token, $challenge) {
        $expectedToken = IntegrationsSecrets::getMetaVerifyToken();

        if ($mode === "subscribe" && $token !== null && hash_equals($expectedToken, (string)$token)) {
            return (string)$challenge;
        }

        return false;
    }

    /**
     * Normaliza os eventos da Meta para o formato unificado Sellex/n8n
     */
    public static function parseAndNormalize($payload) {
        if (!is_array($payload) || empty($payload["entry"])) {
            return [];
        }

        $normalizedEvents = [];
        $db = IntegrationsDatabase::getConnection();

        $object = $payload["object"] ?? "page"; // 'page' ou 'instagram'

        foreach ($payload["entry"] as $entry) {
            $externalAccountId = (string)($entry["id"] ?? "");
            $timeMs = $entry["time"] ?? (time() * 1000);
            $occurredAt = date("c", (int)($timeMs / 1000));

            // Localizar a conexão e loja correspondente na base de dados
            $conn = null;
            if ($db) {
                try {
                    $stmt = $db->prepare("
                        SELECT id, store_id, provider, external_account_id, account_name, connection_status
                        FROM social_connections
                        WHERE external_account_id = ? AND connection_status = 'connected'
                        LIMIT 1
                    ");
                    $stmt->execute([$externalAccountId]);
                    $conn = $stmt->fetch();
                } catch (Exception $e) {
                    error_log("[META WEBHOOK STORE LOOKUP ERROR] " . $e->getMessage());
                }
            }

            $storeId = $conn ? (int)$conn["store_id"] : null;
            $connectionId = $conn ? (int)$conn["id"] : null;
            $channel = $conn ? $conn["provider"] : ($object === "instagram" ? "instagram" : "facebook");

            // 1. Eventos de Mensagens (Direct / Messenger)
            if (!empty($entry["messaging"])) {
                foreach ($entry["messaging"] as $msgEvent) {
                    $senderId = (string)($msgEvent["sender"]["id"] ?? "");
                    $recipientId = (string)($msgEvent["recipient"]["id"] ?? "");

                    // Ignorar ecos (mensagens enviadas pela própria página/loja)
                    if (!empty($msgEvent["message"]["is_echo"])) {
                        continue;
                    }

                    if (!empty($msgEvent["message"])) {
                        $externalMsgId = (string)($msgEvent["message"]["mid"] ?? "");
                        $text = (string)($msgEvent["message"]["text"] ?? "");
                        $eventId = Idempotency::generateEventId();

                        // Identificador unificado da conversa externa
                        $conversationId = "conv_{$externalAccountId}_{$senderId}";

                        $normalized = [
                            "event_id" => $eventId,
                            "event_type" => "social.message.received",
                            "version" => "1.0",
                            "occurred_at" => $occurredAt,
                            "store_id" => $storeId,
                            "channel" => $channel,
                            "connection_id" => $connectionId,
                            "external_account_id" => $externalAccountId,
                            "conversation_id" => $conversationId,
                            "customer" => [
                                "external_id" => $senderId,
                                "display_name" => "Cliente " . substr($senderId, -4)
                            ],
                            "message" => [
                                "external_message_id" => $externalMsgId,
                                "text" => $text
                            ],
                            "metadata" => [
                                "source" => "meta_webhook",
                                "object" => $object
                            ]
                        ];

                        $normalizedEvents[] = $normalized;
                    }
                }
            }

            // 2. Eventos de Alterações / Comentários (changes)
            if (!empty($entry["changes"])) {
                foreach ($entry["changes"] as $change) {
                    $field = $change["field"] ?? "";
                    $value = $change["value"] ?? [];

                    if ($field === "comments" || $field === "feed") {
                        $commentId = (string)($value["comment_id"] ?? ($value["post_id"] ?? ""));
                        $fromId = (string)($value["from"]["id"] ?? "");
                        $fromName = (string)($value["from"]["name"] ?? "Cliente");
                        $message = (string)($value["message"] ?? "");
                        $eventId = Idempotency::generateEventId();

                        $normalized = [
                            "event_id" => $eventId,
                            "event_type" => "social.comment.received",
                            "version" => "1.0",
                            "occurred_at" => $occurredAt,
                            "store_id" => $storeId,
                            "channel" => $channel,
                            "connection_id" => $connectionId,
                            "external_account_id" => $externalAccountId,
                            "conversation_id" => "comment_{$commentId}",
                            "customer" => [
                                "external_id" => $fromId,
                                "display_name" => $fromName
                            ],
                            "message" => [
                                "external_message_id" => $commentId,
                                "text" => $message
                            ],
                            "metadata" => [
                                "source" => "meta_webhook",
                                "field" => $field
                            ]
                        ];

                        $normalizedEvents[] = $normalized;
                    }
                }
            }
        }

        return $normalizedEvents;
    }
}
