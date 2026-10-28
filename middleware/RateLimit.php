<?php
// ─────────────────────────────────────────────────────────────
// MIDDLEWARE DE CONTROLO DE FREQUÊNCIA (RateLimit.php)
// Prevenção de Abuso, Ataques de Força Bruta e Sobrecarga
// Localização: /api/integrations/middleware/RateLimit.php
// ─────────────────────────────────────────────────────────────

require_once __DIR__ . "/../utils/JsonResponse.php";

class RateLimit {

    /**
     * Limita a taxa de requisições por cliente / IP
     * @param int $maxRequests Número máximo de requisições na janela
     * @param int $decaySeconds Duração da janela temporal em segundos
     */
    public static function handle($maxRequests = 120, $decaySeconds = 60) {
        $ip = $_SERVER["REMOTE_ADDR"] ?? "unknown";
        $key = "rate_limit_" . md5($ip);

        $tempDir = sys_get_temp_dir() . "/sellex_rate_limits";
        if (!is_dir($tempDir)) {
            @mkdir($tempDir, 0777, true);
        }

        $filePath = $tempDir . "/" . $key . ".json";
        $now = time();

        $data = ["count" => 0, "start_time" => $now];
        if (file_exists($filePath)) {
            $content = @file_get_contents($filePath);
            if ($content) {
                $decoded = json_decode($content, true);
                if ($decoded && isset($decoded["start_time"])) {
                    $data = $decoded;
                }
            }
        }

        // Renovar janela expirada
        if ($now - $data["start_time"] > $decaySeconds) {
            $data = ["count" => 1, "start_time" => $now];
        } else {
            $data["count"]++;
        }

        @file_put_contents($filePath, json_encode($data), LOCK_EX);

        if ($data["count"] > $maxRequests) {
            $retryAfter = $decaySeconds - ($now - $data["start_time"]);
            if (!headers_sent()) {
                header("Retry-After: " . max(1, $retryAfter));
            }
            JsonResponse::tooManyRequests("Limite de requisições excedido. Tente novamente em {$retryAfter} segundos.");
        }

        return true;
    }
}
