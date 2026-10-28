<?php
// ─────────────────────────────────────────────────────────────
// GESTÃO SEGURA DE SEGREDOS E CIFRAGEM AUTENTICADA (secrets.php)
// Cifragem Criptográfica AES-256-GCM com Tag e Nonce
// Localização: /api/integrations/config/secrets.php
// ─────────────────────────────────────────────────────────────

if (file_exists(__DIR__ . "/../config/app.php")) {
    require_once __DIR__ . "/../config/app.php";
}

class IntegrationsSecrets {

    private static function get($key, $default = null) {
        $val = getenv($key);
        if ($val !== false && $val !== null && $val !== "") {
            return $val;
        }
        if (isset($_ENV[$key]) && $_ENV[$key] !== "") {
            return $_ENV[$key];
        }
        return $default;
    }

    /**
     * Token Bearer que o n8n utiliza para autenticar chamadas na API Sellex
     */
    public static function getN8nServiceToken() {
        return self::get("N8N_API_KEY", self::get("N8N_SERVICE_TOKEN", "sellex_n8n_live_sec_7a9f82bc19405d6e2e8c"));
    }

    /**
     * URL do webhook do n8n para onde os eventos normalizados são encaminhados
     */
    public static function getN8nWebhookUrl() {
        return self::get("N8N_WEBHOOK_URL", "https://automation.sellex.ao/webhook/sellex-social-events");
    }

    /**
     * Meta App ID (Facebook / Instagram)
     */
    public static function getMetaAppId() {
        return self::get("META_APP_ID", "");
    }

    /**
     * Meta App Secret (usado na validação de assinatura X-Hub-Signature-256 e no OAuth)
     */
    public static function getMetaAppSecret() {
        return self::get("META_APP_SECRET", "meta_sec_placeholder_sellex_app");
    }

    /**
     * Token de verificação de Webhook Meta (desafio GET /webhooks/meta)
     */
    public static function getMetaVerifyToken() {
        return self::get("META_WEBHOOK_VERIFY_TOKEN", self::get("META_VERIFY_TOKEN", "sellex_meta_webhook_verify_token_2026"));
    }

    /**
     * Chave mestra para cifragem AES-256 (32 bytes binários)
     */
    public static function getEncryptionKey() {
        $key = self::get("INTEGRATIONS_ENCRYPTION_KEY", "sellex_soc_enc_key_9837482910482");
        return hash("sha256", $key, true);
    }

    /**
     * Cifragem Autenticada AES-256-GCM (Nonce 12 bytes + Tag 16 bytes)
     */
    public static function encryptToken($plainToken) {
        if (empty($plainToken)) return "";
        $key = self::getEncryptionKey();
        $iv = openssl_random_pseudo_bytes(12); // 12 bytes nonce recomendado para GCM
        $tag = "";
        $ciphertext = openssl_encrypt($plainToken, "aes-256-gcm", $key, OPENSSL_RAW_DATA, $iv, $tag, "", 16);
        return base64_encode("GCM:" . $iv . $tag . $ciphertext);
    }

    /**
     * Decifragem Autenticada AES-256-GCM com validação de integridade da Tag
     */
    public static function decryptToken($encryptedToken) {
        if (empty($encryptedToken)) return "";
        $raw = base64_decode($encryptedToken);
        if (!$raw) return "";

        $key = self::getEncryptionKey();

        // Modo AES-256-GCM moderno
        if (strpos($raw, "GCM:") === 0) {
            $data = substr($raw, 4);
            if (strlen($data) < 28) return ""; // 12 bytes IV + 16 bytes Tag
            $iv = substr($data, 0, 12);
            $tag = substr($data, 12, 16);
            $ciphertext = substr($data, 28);
            $decrypted = openssl_decrypt($ciphertext, "aes-256-gcm", $key, OPENSSL_RAW_DATA, $iv, $tag);
            return $decrypted !== false ? $decrypted : "";
        }

        // Fallback de compatibilidade retroativa para tokens CBC legados
        if (strlen($raw) >= 17) {
            $iv = substr($raw, 0, 16);
            $ciphertext = substr($raw, 16);
            return openssl_decrypt($ciphertext, "aes-256-cbc", $key, OPENSSL_RAW_DATA, $iv) ?: "";
        }

        return "";
    }
}
