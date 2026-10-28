<?php
// ─────────────────────────────────────────────────────────────
// SCRIPT DE MIGRAÇÃO CONTROLADA (migrate.php)
// Sellex Social Integrations & n8n Automation Engine
// Execução: php api/integrations/migrate.php
// ─────────────────────────────────────────────────────────────

require_once __DIR__ . "/config/app.php";
require_once __DIR__ . "/config/database.php";

echo "========================================================\n";
echo "   SELLEX INTEGRATIONS — EXECUTOR DE MIGRAÇÃO OFICIAL   \n";
echo "========================================================\n\n";

$db = IntegrationsDatabase::getConnection();

if (!$db) {
    echo "[ERRO] Não foi possível conectar à base de dados.\n";
    exit(1);
}

try {
    echo "A provisionar tabelas de integração social...\n";
    IntegrationsDatabase::ensureTablesExist($db, true); // Forçar execução da migração

    $tables = ["social_connections", "social_events", "social_conversations", "social_outbound_messages"];
    foreach ($tables as $t) {
        $stmt = $db->query("SHOW TABLES LIKE '{$t}'");
        if ($stmt && $stmt->fetch()) {
            echo " [OK] Tabela '{$t}' verificada com sucesso.\n";
        } else {
            echo " [AVISO] Tabela '{$t}' não encontrada após migração.\n";
        }
    }

    echo "\nMigração concluída com sucesso.\n";
    exit(0);
} catch (Exception $e) {
    echo "\n[FALHA NA MIGRAÇÃO] " . $e->getMessage() . "\n";
    exit(1);
}
