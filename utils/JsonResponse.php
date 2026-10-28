<?php
// ─────────────────────────────────────────────────────────────
// PADRONIZAÇÃO DE RESPOSTAS JSON (JsonResponse.php)
// Sellex Social Integrations & n8n Automation Engine (PRD v1.0)
// Localização: /api/integrations/utils/JsonResponse.php
// ─────────────────────────────────────────────────────────────

class JsonResponse {

    /**
     * Emite uma resposta JSON padronizada e encerra a execução
     */
    public static function send($success, $data = null, $error = null, $httpCode = 200) {
        if (!headers_sent()) {
            http_response_code($httpCode);
            header("Content-Type: application/json; charset=UTF-8");
            header("X-Content-Type-Options: nosniff");
            header("X-Frame-Options: DENY");
        }

        $payload = [
            "success" => (bool)$success,
            "data" => $data,
            "error" => $error
        ];

        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /**
     * Resposta de Sucesso Padrão (HTTP 200)
     */
    public static function success($data = null, $httpCode = 200) {
        self::send(true, $data, null, $httpCode);
    }

    /**
     * Resposta de Evento Aceite para Processamento Assíncrono (HTTP 202)
     */
    public static function accepted($data = null) {
        self::send(true, $data, null, 202);
    }

    /**
     * Resposta de Erro Padronizada
     */
    public static function error($code, $message, $httpCode = 400) {
        self::send(false, null, [
            "code" => $code,
            "message" => $message
        ], $httpCode);
    }

    /**
     * Erro 401 - Não Autorizado
     */
    public static function unauthorized($message = "Credenciais não fornecidas ou inválidas.") {
        self::error("UNAUTHORIZED", $message, 401);
    }

    /**
     * Erro 403 - Acesso Proibido
     */
    public static function forbidden($message = "Acesso proibido ao recurso solicitado.") {
        self::error("FORBIDDEN", $message, 403);
    }

    /**
     * Erro 404 - Não Encontrado
     */
    public static function notFound($message = "O recurso solicitado não foi encontrado.") {
        self::error("NOT_FOUND", $message, 404);
    }

    /**
     * Erro 409 - Conflito / Duplicação
     */
    public static function conflict($message = "O registo ou operação já existe.") {
        self::error("CONFLICT", $message, 409);
    }

    /**
     * Erro 422 - Entidade Não Processável / Validação Falhou
     */
    public static function unprocessable($message = "Dados inválidos fornecidos no pedido.") {
        self::error("UNPROCESSABLE_ENTITY", $message, 422);
    }

    /**
     * Erro 429 - Limite de Requisições Excedido
     */
    public static function tooManyRequests($message = "Limite de pedidos atingido. Tente novamente mais tarde.") {
        self::error("TOO_MANY_REQUESTS", $message, 429);
    }

    /**
     * Erro 500 - Falha Interna
     */
    public static function serverError($message = "Ocorreu um erro interno no servidor.") {
        self::error("INTERNAL_SERVER_ERROR", $message, 500);
    }
}
