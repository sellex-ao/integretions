<?php
// ─────────────────────────────────────────────────────────────
// MIDDLEWARE DE VALIDAÇÃO DE ASSINATURA META (VerifyMetaSignature.php)
// Validação Criptográfica HMAC-SHA256 (X-Hub-Signature-256)
// Localização: /api/integrations/middleware/VerifyMetaSignature.php
// ─────────────────────────────────────────────────────────────

require_once __DIR__ . "/../config/secrets.php";
require_once __DIR__ . "/../utils/JsonResponse.php";

class VerifyMetaSignature {

    /**
     * Valida a assinatura criptográfica do payload recebido da Meta
     * @param string $rawBody Corpo original sem modificações
     */
    public static function handle($rawBody) {
        $signatureHeader = self::getHeader("X-Hub-Signature-256");

        if (empty($signatureHeader)) {
            JsonResponse::unauthorized("Assinatura de segurança X-Hub-Signature-256 ausente.");
        }

        // Formato esperado: sha256=<hash_hex>
        $parts = explode("=", $signatureHeader, 2);
        if (count($parts) !== 2 || strtolower($parts[0]) !== "sha256") {
            JsonResponse::unauthorized("Formato de assinatura Meta inválido.");
        }

        $receivedHash = $parts[1];
        $appSecret = IntegrationsSecrets::getMetaAppSecret();

        // Cálculo do HMAC-SHA256 sobre o corpo bruto da mensagem
        $expectedHash = hash_hmac("sha256", $rawBody, $appSecret);

        if (!hash_equals($expectedHash, $receivedHash)) {
            error_log("[META SIGNATURE MISMATCH] Assinatura calculada difere da recebida.");
            JsonResponse::unauthorized("Assinatura digital Meta inválida.");
        }

        return true;
    }

    private static function getHeader($name) {
        $normalizedName = strtolower($name);
        if (function_exists("getallheaders")) {
            $headers = getallheaders();
            if ($headers) {
                foreach ($headers as $k => $v) {
                    if (strtolower($k) === $normalizedName) {
                        return $v;
                    }
                }
            }
        }

        $serverKey = "HTTP_" . strtoupper(str_replace("-", "_", $name));
        return $_SERVER[$serverKey] ?? null;
    }
}
