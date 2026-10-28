<?php
// ─────────────────────────────────────────────────────────────
// PROCESSO DE REENTREGA CONTROLADA DE EVENTOS (cron/retry.php)
// Sellex Social Integrations & n8n Automation Engine
// Execução: php api/integrations/cron/retry.php
// ─────────────────────────────────────────────────────────────

require_once __DIR__ . "/../config/app.php";
require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../config/secrets.php";
require_once __DIR__ . "/../services/SocialEventService.php";
require_once __DIR__ . "/../services/N8nService.php";

// 1. Mutex: Impedir execuções concorrentes com lock exclusivo não-bloqueante
$lockFile = sys_get_temp_dir() . "/sellex_integrations_retry.lock";
$lockFp = fopen($lockFile, "w+");

if (!flock($lockFp, LOCK_EX | LOCK_NB)) {
    echo json_encode([
        "success" => false,
        "message" => "Outro processo de reentrega já está em execução."
    ]) . PHP_EOL;
    exit(0);
}

// 2. Conectar à base de dados
$db = IntegrationsDatabase::getConnection();
if (!$db) {
    echo json_encode(["success" => false, "message" => "Base de dados indisponível."]) . PHP_EOL;
    flock($lockFp, LOCK_UN);
    fclose($lockFp);
    exit(1);
}

// 3. Buscar eventos pendentes com menos de 5 tentativas
$maxAttempts = 5;
$stmt = $db->prepare("
    SELECT * FROM social_events 
    WHERE processing_status = 'pending' AND attempt_count < ?
    ORDER BY attempt_count ASC, id ASC
    LIMIT 50
");
$stmt->execute([$maxAttempts]);
$pendingEvents = $stmt->fetchAll();

$processed = 0;
$forwarded = 0;
$skipped = 0;
$failed = 0;

// Escala de Backoff Exponencial em segundos
$backoffs = [
    0 => 0,       // 1ª tentativa
    1 => 60,      // 2ª tentativa: após 1 minuto
    2 => 300,     // 3ª tentativa: após 5 minutos
    3 => 900,     // 4ª tentativa: após 15 minutos
    4 => 1800     // 5ª tentativa: após 30 minutos
];

$now = time();

foreach ($pendingEvents as $row) {
    $eventId = $row["event_id"];
    $attemptCount = (int)$row["attempt_count"];
    $receivedTime = strtotime($row["received_at"]);

    $requiredDelay = $backoffs[$attemptCount] ?? 1800;
    $timeSinceReceived = $now - $receivedTime;

    // Respeitar o intervalo de backoff progressivo
    if ($timeSinceReceived < $requiredDelay) {
        $skipped++;
        continue;
    }

    $processed++;
    $payload = json_decode($row["payload_json"], true);
    if (!is_array($payload)) {
        SocialEventService::updateStatus($eventId, "failed_permanently", "Payload JSON corrompido");
        $failed++;
        continue;
    }

    // Tentar entrega ao n8n
    $dispatchResult = N8nService::dispatch($payload);

    if ($dispatchResult["success"]) {
        $forwarded++;
    } else {
        $errorMsg = $dispatchResult["error"] ?? "Falha no envio";
        $httpCode = (int)($dispatchResult["http_code"] ?? 0);

        // Se for erro permanente do cliente (400, 404, 422), não retentar
        if ($httpCode >= 400 && $httpCode < 500 && $httpCode !== 429) {
            SocialEventService::updateStatus($eventId, "failed_permanently", "Erro permanente do n8n: {$errorMsg}");
            $failed++;
        } else {
            // Erro temporário (5xx, timeout, rede): incrementar tentativas
            $newAttempts = $attemptCount + 1;
            if ($newAttempts >= $maxAttempts) {
                SocialEventService::updateStatus($eventId, "failed_exhausted", "Limite de {$maxAttempts} tentativas atingido: {$errorMsg}");
                $failed++;
            } else {
                SocialEventService::incrementAttempt($eventId, $errorMsg);
            }
        }
    }
}

// 4. Libertar o lock
flock($lockFp, LOCK_UN);
fclose($lockFp);

$summary = [
    "success" => true,
    "timestamp" => date("c"),
    "total_pending_found" => count($pendingEvents),
    "processed" => $processed,
    "forwarded" => $forwarded,
    "skipped_backoff" => $skipped,
    "failed" => $failed
];

echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit(0);
