<?php
// ─────────────────────────────────────────────────────────────
// CONEXÃO DE BASE DE DADOS (PDO) PARA O MÓDULO DE INTEGRAÇÕES
// Isolamento Resiliente e Migrações Controladas
// Localização: /api/integrations/config/database.php
// ─────────────────────────────────────────────────────────────

if (file_exists(__DIR__ . "/../config/app.php")) {
    require_once __DIR__ . "/../config/app.php";
}

class IntegrationsDatabase {
    private static $db = null;
    private static $tablesEnsured = false;

    /**
     * Retorna a conexão PDO oficial da Sellex
     */
    public static function getConnection() {
        if (self::$db !== null) {
            return self::$db;
        }

        // Tentar reaproveitar a classe Database do núcleo caso já carregada
        if (class_exists("Database")) {
            self::$db = Database::getConnection();
        } else {
            $host = getenv("DB_HOST") ?: "127.0.0.1";
            $name = getenv("DB_NAME") ?: "sellex_db";
            $user = getenv("DB_USER") ?: "root";
            $pass = getenv("DB_PASS") ?: "";

            try {
                $dsn = "mysql:host={$host};dbname={$name};charset=utf8mb4";
                self::$db = new PDO($dsn, $user, $pass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false
                ]);
            } catch (PDOException $e) {
                // Fallback para desenvolvimento local
                if ($host === "127.0.0.1" || $host === "localhost") {
                    try {
                        self::$db = new PDO("mysql:host=localhost;dbname=sellex_db;charset=utf8mb4", "root", "", [
                            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                            PDO::ATTR_EMULATE_PREPARES => false
                        ]);
                    } catch (PDOException $localErr) {
                        error_log("[INTEGRATIONS DB CONN ERROR] " . $localErr->getMessage());
                    }
                }
            }
        }

        if (self::$db) {
            self::ensureTablesExist(self::$db);
        }

        return self::$db;
    }

    /**
     * Provisionamento controlado das tabelas de integração
     * Em PRODUÇÃO, o DDL automático é desativado para segurança e performance.
     * Use o ficheiro migration_integrations_social.sql no cPanel.
     */
    public static function ensureTablesExist($db, $force = false) {
        if (self::$tablesEnsured || !$db) return;

        $appConfig = file_exists(__DIR__ . "/app.php") ? require __DIR__ . "/app.php" : [];
        $env = strtolower($appConfig["env"] ?? (getenv("APP_ENV") ?: "production"));

        // Em produção, nunca executar DDL (CREATE/ALTER) em runtime sem forçar explicitamente
        if ($env === "production" && !$force) {
            self::$tablesEnsured = true;
            return;
        }

        try {
            // 1. social_connections
            $db->exec("
                CREATE TABLE IF NOT EXISTS `social_connections` (
                    `id` INT AUTO_INCREMENT PRIMARY KEY,
                    `store_id` INT NOT NULL,
                    `provider` VARCHAR(30) NOT NULL,
                    `external_account_id` VARCHAR(120) NOT NULL,
                    `account_name` VARCHAR(150) DEFAULT NULL,
                    `connection_status` VARCHAR(30) NOT NULL DEFAULT 'connected',
                    `granted_scopes` TEXT DEFAULT NULL,
                    `access_token_encrypted` TEXT NOT NULL,
                    `token_expires_at` DATETIME DEFAULT NULL,
                    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX `idx_soc_conn_store_prov` (`store_id`, `provider`),
                    INDEX `idx_soc_conn_ext_acc` (`external_account_id`),
                    INDEX `idx_soc_conn_status` (`connection_status`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            ");

            // 2. social_events
            $db->exec("
                CREATE TABLE IF NOT EXISTS `social_events` (
                    `id` INT AUTO_INCREMENT PRIMARY KEY,
                    `event_id` VARCHAR(64) NOT NULL UNIQUE,
                    `store_id` INT NOT NULL,
                    `connection_id` INT DEFAULT NULL,
                    `provider` VARCHAR(30) NOT NULL,
                    `event_type` VARCHAR(60) NOT NULL,
                    `external_event_id` VARCHAR(120) DEFAULT NULL,
                    `payload_json` JSON NOT NULL,
                    `processing_status` VARCHAR(30) NOT NULL DEFAULT 'pending',
                    `attempt_count` INT NOT NULL DEFAULT 0,
                    `last_error` TEXT DEFAULT NULL,
                    `received_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    `processed_at` TIMESTAMP NULL DEFAULT NULL,
                    INDEX `idx_soc_evt_store_status` (`store_id`, `processing_status`),
                    INDEX `idx_soc_evt_conn` (`connection_id`),
                    INDEX `idx_soc_evt_ext_id` (`external_event_id`),
                    INDEX `idx_soc_evt_type` (`event_type`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            ");

            // 3. social_conversations
            $db->exec("
                CREATE TABLE IF NOT EXISTS `social_conversations` (
                    `id` INT AUTO_INCREMENT PRIMARY KEY,
                    `store_id` INT NOT NULL,
                    `connection_id` INT NOT NULL,
                    `provider` VARCHAR(30) NOT NULL,
                    `external_conversation_id` VARCHAR(120) NOT NULL,
                    `external_customer_id` VARCHAR(120) NOT NULL,
                    `customer_name` VARCHAR(150) DEFAULT NULL,
                    `last_message_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    `conversation_status` VARCHAR(30) NOT NULL DEFAULT 'open',
                    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY `uq_soc_conv` (`store_id`, `provider`, `external_conversation_id`),
                    INDEX `idx_soc_conv_store` (`store_id`),
                    INDEX `idx_soc_conv_conn` (`connection_id`),
                    INDEX `idx_soc_conv_cust` (`external_customer_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            ");

            // 4. social_outbound_messages
            $db->exec("
                CREATE TABLE IF NOT EXISTS `social_outbound_messages` (
                    `id` INT AUTO_INCREMENT PRIMARY KEY,
                    `store_id` INT NOT NULL,
                    `connection_id` INT NOT NULL,
                    `conversation_id` INT DEFAULT NULL,
                    `idempotency_key` VARCHAR(64) NOT NULL UNIQUE,
                    `recipient_external_id` VARCHAR(120) NOT NULL,
                    `message_text` TEXT NOT NULL,
                    `status` VARCHAR(30) NOT NULL DEFAULT 'queued',
                    `external_message_id` VARCHAR(120) DEFAULT NULL,
                    `error_code` VARCHAR(60) DEFAULT NULL,
                    `error_message` TEXT DEFAULT NULL,
                    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    `sent_at` TIMESTAMP NULL DEFAULT NULL,
                    INDEX `idx_soc_out_store` (`store_id`),
                    INDEX `idx_soc_out_conn` (`connection_id`),
                    INDEX `idx_soc_out_conv` (`conversation_id`),
                    INDEX `idx_soc_out_status` (`status`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            ");

            self::$tablesEnsured = true;
        } catch (Exception $e) {
            error_log("[INTEGRATIONS DB SETUP ERROR] " . $e->getMessage());
        }
    }
}
