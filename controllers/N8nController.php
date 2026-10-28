<?php
// ─────────────────────────────────────────────────────────────
// CONTROLADOR DE COMUNICAÇÃO COM O N8N (N8nController.php)
// Contrato de APIs Internas para Workflows e Automação
// Localização: /api/integrations/controllers/N8nController.php
// ─────────────────────────────────────────────────────────────

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../services/SocialEventService.php";
require_once __DIR__ . "/../services/N8nService.php";
require_once __DIR__ . "/../utils/JsonResponse.php";

class N8nController {

    /**
     * POST /n8n/events
     * Recebe um evento normalizado
     */
    public static function receiveEvent($body) {
        if (empty($body) || !is_array($body)) {
            JsonResponse::unprocessable("Payload de evento inválido ou ausente.");
        }

        $res = SocialEventService::recordEvent($body);
        JsonResponse::accepted([
            "event_id" => $res["event_id"],
            "status" => $res["status"],
            "duplicate" => $res["duplicate"]
        ]);
    }

    /**
     * POST /n8n/events/{event_id}/result
     * Regista o resultado do processamento de um workflow do n8n
     */
    public static function recordResult($eventId, $body) {
        if (empty($eventId)) {
            JsonResponse::unprocessable("Identificador do evento obrigatório.");
        }

        $status = $body["status"] ?? "processed";
        $error = $body["error"] ?? null;

        $updated = SocialEventService::updateStatus($eventId, $status, $error);
        if ($updated) {
            JsonResponse::success([
                "event_id" => $eventId,
                "status" => $status,
                "updated" => true
            ]);
        } else {
            JsonResponse::notFound("Evento não encontrado para atualização.");
        }
    }

    /**
     * GET /n8n/stores/{store_id}/context
     * Consulta o contexto autorizado da loja para a automação
     */
    public static function getStoreContext($storeId) {
        $storeId = (int)$storeId;
        if ($storeId <= 0) {
            JsonResponse::unprocessable("ID de loja inválido.");
        }

        $db = IntegrationsDatabase::getConnection();
        $stmt = $db->prepare("
            SELECT id, store_slug, store_name, seller_name, whatsapp, email, location, description, segment, activity
            FROM stores 
            WHERE id = ? 
            LIMIT 1
        ");
        $stmt->execute([$storeId]);
        $store = $stmt->fetch();

        if (!$store) {
            JsonResponse::notFound("Loja não encontrada.");
        }

        JsonResponse::success([
            "store_id" => (int)$store["id"],
            "name" => $store["store_name"],
            "slug" => $store["store_slug"],
            "seller" => $store["seller_name"],
            "support_whatsapp" => $store["whatsapp"],
            "email" => $store["email"],
            "location" => $store["location"],
            "description" => $store["description"],
            "segment" => $store["segment"],
            "activity" => $store["activity"]
        ]);
    }

    /**
     * GET /n8n/stores/{store_id}/products
     * Consulta produtos e stock com isolamento estrito entre lojas
     */
    public static function getStoreProducts($storeId) {
        $storeId = (int)$storeId;
        if ($storeId <= 0) {
            JsonResponse::unprocessable("ID de loja inválido.");
        }

        $q = trim((string)($_GET["q"] ?? ""));
        $category = trim((string)($_GET["category"] ?? ""));

        $db = IntegrationsDatabase::getConnection();

        $sql = "
            SELECT id, name, price, discount, category, stock, description, image, status, is_highlight
            FROM products
            WHERE store_id = ? AND status = 'active'
        ";
        $params = [$storeId];

        if (!empty($q)) {
            $sql .= " AND (name LIKE ? OR description LIKE ?)";
            $params[] = "%{$q}%";
            $params[] = "%{$q}%";
        }

        if (!empty($category)) {
            $sql .= " AND category = ?";
            $params[] = $category;
        }

        $sql .= " ORDER BY is_highlight DESC, id DESC LIMIT 50";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $products = $stmt->fetchAll();

        $formatted = array_map(function($p) {
            $stock = (int)$p["stock"];
            return [
                "id" => (int)$p["id"],
                "name" => $p["name"],
                "price" => (float)$p["price"],
                "discount_percentage" => (int)$p["discount"],
                "category" => $p["category"],
                "stock" => $stock,
                "in_stock" => $stock > 0,
                "description" => $p["description"],
                "image" => !empty($p["image"]) ? $p["image"] : null,
                "is_highlight" => (bool)$p["is_highlight"]
            ];
        }, $products);

        JsonResponse::success([
            "store_id" => $storeId,
            "total" => count($formatted),
            "products" => $formatted
        ]);
    }

    /**
     * GET /n8n/stores/{store_id}/customers/{customer_id}
     * Consulta dados autorizados de um cliente da loja
     */
    public static function getStoreCustomer($storeId, $customerId) {
        $storeId = (int)$storeId;
        $customerId = trim((string)$customerId);

        if ($storeId <= 0 || empty($customerId)) {
            JsonResponse::unprocessable("Identificador de loja e cliente obrigatórios.");
        }

        $db = IntegrationsDatabase::getConnection();

        // Pode buscar por ID numérico ou telefone
        if (is_numeric($customerId)) {
            $stmt = $db->prepare("SELECT id, name, phone, total_orders, total_spent, last_order_date FROM clients WHERE store_id = ? AND id = ? LIMIT 1");
            $stmt->execute([$storeId, (int)$customerId]);
        } else {
            $stmt = $db->prepare("SELECT id, name, phone, total_orders, total_spent, last_order_date FROM clients WHERE store_id = ? AND phone = ? LIMIT 1");
            $stmt->execute([$storeId, $customerId]);
        }

        $client = $stmt->fetch();
        if (!$client) {
            JsonResponse::notFound("Cliente não encontrado nesta loja.");
        }

        JsonResponse::success([
            "customer_id" => (int)$client["id"],
            "name" => $client["name"],
            "phone" => $client["phone"],
            "total_orders" => (int)$client["total_orders"],
            "total_spent" => (float)$client["total_spent"],
            "last_order_date" => $client["last_order_date"]
        ]);
    }

    /**
     * POST /n8n/actions/send-message
     * Envia mensagem oficial autorizada com controlo estrito de idempotência
     */
    public static function sendMessage($body) {
        $storeId = (int)($body["store_id"] ?? 0);
        $connectionId = (int)($body["connection_id"] ?? 0);
        $recipientId = trim((string)($body["recipient_external_id"] ?? ""));
        $messageText = trim((string)($body["message_text"] ?? ""));
        $idempotencyKey = trim((string)($body["idempotency_key"] ?? ""));

        if ($storeId <= 0 || $connectionId <= 0 || empty($recipientId) || empty($messageText)) {
            JsonResponse::unprocessable("Campos obrigatórios: store_id, connection_id, recipient_external_id, message_text.");
        }

        if (empty($idempotencyKey)) {
            $idempotencyKey = "msg_" . md5("{$storeId}_{$connectionId}_{$recipientId}_" . microtime(true));
        }

        try {
            $result = N8nService::sendOutboundMessage($storeId, $connectionId, $recipientId, $messageText, $idempotencyKey);
            JsonResponse::success($result);
        } catch (Exception $e) {
            JsonResponse::error("SEND_MESSAGE_FAILED", $e->getMessage(), 400);
        }
    }

    /**
     * GET /n8n/events/{event_id}
     * Consulta o estado e telemetria de um evento
     */
    public static function getEvent($eventId) {
        if (empty($eventId)) {
            JsonResponse::unprocessable("Identificador de evento obrigatório.");
        }

        $event = SocialEventService::getEvent($eventId);
        if (!$event) {
            JsonResponse::notFound("Evento não encontrado.");
        }

        JsonResponse::success($event);
    }
}
