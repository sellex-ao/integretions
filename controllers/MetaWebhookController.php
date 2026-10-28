<?php
// ─────────────────────────────────────────────────────────────
// CONTROLADOR DE WEBHOOKS META (MetaWebhookController.php)
// Verificação de Desafio (GET) e Recepção Segura de Eventos (POST)
// Localização: /api/integrations/controllers/MetaWebhookController.php
// ─────────────────────────────────────────────────────────────

require_once __DIR__ . "/../middleware/VerifyMetaSignature.php";
require_once __DIR__ . "/../services/MetaWebhookService.php";
require_once __DIR__ . "/../services/SocialEventService.php";
require_once __DIR__ . "/../services/N8nService.php";
require_once __DIR__ . "/../utils/JsonResponse.php";

class MetaWebhookController {

    /**
     * Responde ao desafio de verificação inicial enviado pela Meta
     * GET /webhooks/meta
     */
    public static function verify() {
        $mode = $_GET["hub_mode"] ?? ($_GET["hub.mode"] ?? null);
        $token = $_GET["hub_verify_token"] ?? ($_GET["hub.verify_token"] ?? null);
        $challenge = $_GET["hub_challenge"] ?? ($_GET["hub.challenge"] ?? null);

        $validChallenge = MetaWebhookService::verifyChallenge($mode, $token, $challenge);

        if ($validChallenge !== false) {
            http_response_code(200);
            header("Content-Type: text/plain; charset=UTF-8");
            echo $validChallenge;
            exit;
        }

        JsonResponse::forbidden("Token de verificação do webhook inválido.");
    }

    /**
     * Recebe notificações de eventos em tempo real da Meta
     * POST /webhooks/meta
     */
    public static function receive() {
        // 1. Ler corpo original da requisição HTTP
        $rawBody = file_get_contents("php://input");
        if (empty($rawBody)) {
            JsonResponse::error("EMPTY_BODY", "Corpo da requisição vazio.", 400);
        }

        // 2. Verificar assinatura digital X-Hub-Signature-256
        VerifyMetaSignature::handle($rawBody);

        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            JsonResponse::error("INVALID_JSON", "Payload JSON inválido.", 400);
        }

        // 3. Normalizar eventos da Meta para o formato unificado
        $events = MetaWebhookService::parseAndNormalize($payload);

        $recordedCount = 0;
        $forwardedCount = 0;

        foreach ($events as $event) {
            // 4. Gravar de forma idempotente na tabela social_events
            $recResult = SocialEventService::recordEvent($event);
            $recordedCount++;

            // 5. Se não for duplicado e a loja foi identificada, despachar para o n8n
            if (!$recResult["duplicate"] && !empty($event["store_id"])) {
                $dispatchRes = N8nService::dispatch($event);
                if ($dispatchRes["success"]) {
                    $forwardedCount++;
                }
            }
        }

        // 6. Resposta rápida HTTP 200 à Meta para confirmar recepção
        JsonResponse::success([
            "status" => "EVENT_RECEIVED",
            "events_received" => count($events),
            "events_recorded" => $recordedCount,
            "events_forwarded" => $forwardedCount
        ], 200);
    }
}
