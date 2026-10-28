-- ─────────────────────────────────────────────────────────────
-- SELLEX SOCIAL INTEGRATIONS & N8N AUTOMATION (PRD v1.0)
-- Script de Migração Oficial para cPanel (MySQL / MariaDB)
-- Compatibilidade Universal: CHARSET=utf8mb4 com Índices Rápidos
-- Localização: /api/integrations/migration_integrations_social.sql
-- ─────────────────────────────────────────────────────────────

-- 1. Tabela de Conexões Sociais das Lojas (Meta / Instagram / WhatsApp)
CREATE TABLE IF NOT EXISTS `social_connections` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `store_id` INT NOT NULL,
    `provider` VARCHAR(30) NOT NULL COMMENT 'facebook, instagram, whatsapp',
    `external_account_id` VARCHAR(120) NOT NULL COMMENT 'Page ID ou Instagram Account ID',
    `account_name` VARCHAR(150) DEFAULT NULL COMMENT 'Nome visível da página ou conta comercial',
    `connection_status` VARCHAR(30) NOT NULL DEFAULT 'connected' COMMENT 'connected, disconnected, expired, revoked',
    `granted_scopes` TEXT DEFAULT NULL COMMENT 'Escopos autorizados separados por vírgula',
    `access_token_encrypted` TEXT NOT NULL COMMENT 'Token de acesso cifrado em AES-256',
    `token_expires_at` DATETIME DEFAULT NULL COMMENT 'Data de expiração do token de longa duração',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_soc_conn_store_prov` (`store_id`, `provider`),
    INDEX `idx_soc_conn_ext_acc` (`external_account_id`),
    INDEX `idx_soc_conn_status` (`connection_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. Tabela de Eventos Sociais Recebidos (Webhooks Meta)
CREATE TABLE IF NOT EXISTS `social_events` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `event_id` VARCHAR(64) NOT NULL UNIQUE COMMENT 'Identificador único do evento no formato evt_...',
    `store_id` INT NOT NULL,
    `connection_id` INT DEFAULT NULL,
    `provider` VARCHAR(30) NOT NULL,
    `event_type` VARCHAR(60) NOT NULL COMMENT 'Ex: social.message.received, social.comment.received',
    `external_event_id` VARCHAR(120) DEFAULT NULL COMMENT 'ID externo recebido da Meta',
    `payload_json` JSON NOT NULL COMMENT 'Payload normalizado para o n8n',
    `processing_status` VARCHAR(30) NOT NULL DEFAULT 'pending' COMMENT 'pending, forwarded, processed, failed, ignored',
    `attempt_count` INT NOT NULL DEFAULT 0,
    `last_error` TEXT DEFAULT NULL,
    `received_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `processed_at` TIMESTAMP NULL DEFAULT NULL,
    INDEX `idx_soc_evt_store_status` (`store_id`, `processing_status`),
    INDEX `idx_soc_evt_conn` (`connection_id`),
    INDEX `idx_soc_evt_ext_id` (`external_event_id`),
    INDEX `idx_soc_evt_type` (`event_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. Tabela de Conversas Sociais com Clientes
CREATE TABLE IF NOT EXISTS `social_conversations` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `store_id` INT NOT NULL,
    `connection_id` INT NOT NULL,
    `provider` VARCHAR(30) NOT NULL,
    `external_conversation_id` VARCHAR(120) NOT NULL COMMENT 'ID da conversa na Meta',
    `external_customer_id` VARCHAR(120) NOT NULL COMMENT 'ID do cliente externo (PSID / IGSID)',
    `customer_name` VARCHAR(150) DEFAULT NULL,
    `last_message_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `conversation_status` VARCHAR(30) NOT NULL DEFAULT 'open' COMMENT 'open, closed, pending_human',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_soc_conv` (`store_id`, `provider`, `external_conversation_id`),
    INDEX `idx_soc_conv_store` (`store_id`),
    INDEX `idx_soc_conv_conn` (`connection_id`),
    INDEX `idx_soc_conv_cust` (`external_customer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. Tabela de Mensagens Enviadas (Outbound Actions do n8n / API)
CREATE TABLE IF NOT EXISTS `social_outbound_messages` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `store_id` INT NOT NULL,
    `connection_id` INT NOT NULL,
    `conversation_id` INT DEFAULT NULL,
    `idempotency_key` VARCHAR(64) NOT NULL UNIQUE COMMENT 'Chave de idempotência para evitar envio duplo',
    `recipient_external_id` VARCHAR(120) NOT NULL COMMENT 'ID do destinatário na Meta (PSID/IGSID)',
    `message_text` TEXT NOT NULL,
    `status` VARCHAR(30) NOT NULL DEFAULT 'queued' COMMENT 'queued, sent, failed, rejected',
    `external_message_id` VARCHAR(120) DEFAULT NULL COMMENT 'ID da mensagem devolvido pela Meta',
    `error_code` VARCHAR(60) DEFAULT NULL,
    `error_message` TEXT DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `sent_at` TIMESTAMP NULL DEFAULT NULL,
    INDEX `idx_soc_out_store` (`store_id`),
    INDEX `idx_soc_out_conn` (`connection_id`),
    INDEX `idx_soc_out_conv` (`conversation_id`),
    INDEX `idx_soc_out_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
