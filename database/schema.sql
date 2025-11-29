-- Script SQL gerado a partir das migrations PHP
-- Ajuste o nome do banco antes de rodar, se necessário.

-- USE easychart-saas;

-- 1) Tabela users (20251126_000000_create_users_table)
CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(150) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2) Tabela spreadsheets (20251127_090000_create_spreadsheets_table)
CREATE TABLE IF NOT EXISTS spreadsheets (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    stored_name VARCHAR(255) NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    size_bytes BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_spreadsheets_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- 3) Tabela charts (20251127_090100_create_charts_table)
CREATE TABLE IF NOT EXISTS charts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    spreadsheet_id INT UNSIGNED NULL,
    prompt TEXT NOT NULL,
    chart_type VARCHAR(100) NULL,
    data_json LONGTEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_charts_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_charts_spreadsheet
        FOREIGN KEY (spreadsheet_id) REFERENCES spreadsheets(id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- 4) Tabela user_settings (20251127_090200_create_user_settings_table)
CREATE TABLE IF NOT EXISTS user_settings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    notification_email_enabled TINYINT(1) NOT NULL DEFAULT 1,
    notification_weekly_summary TINYINT(1) NOT NULL DEFAULT 1,
    notification_product_updates TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL,
    UNIQUE KEY uniq_user_settings_user (user_id),
    CONSTRAINT fk_user_settings_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- 5) Tabela api_configs (20251127_090300_create_api_configs_table)
CREATE TABLE IF NOT EXISTS api_configs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    provider VARCHAR(50) NOT NULL,
    api_key TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL,
    CONSTRAINT fk_api_configs_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- 6) Tabela plans (20251127_160000_create_plans_table)
CREATE TABLE IF NOT EXISTS plans (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    slug VARCHAR(50) NOT NULL UNIQUE,
    price_cents INT UNSIGNED NOT NULL DEFAULT 0,
    currency CHAR(3) NOT NULL DEFAULT 'BRL',
    monthly_spreadsheet_limit INT NULL,
    monthly_chart_limit INT NULL,
    description TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 7) ALTER users – role e last_login_at (20251127_090400_add_role_and_last_login_to_users)
ALTER TABLE users
    ADD COLUMN role ENUM('user','super_admin') NOT NULL DEFAULT 'user' AFTER password_hash,
    ADD COLUMN last_login_at TIMESTAMP NULL DEFAULT NULL AFTER created_at;

-- 8) ALTER users – is_active (20251127_103000_add_is_active_to_users)
ALTER TABLE users
    ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER role;

-- 9) ALTER users – campos de plano e relacionamentos (20251127_160100_add_plan_fields_to_users)
ALTER TABLE users
    ADD COLUMN plan_id INT UNSIGNED NULL AFTER role,
    ADD COLUMN plan_status ENUM('free','pending','active','past_due','canceled') NOT NULL DEFAULT 'free' AFTER plan_id,
    ADD COLUMN plan_activated_at TIMESTAMP NULL DEFAULT NULL AFTER plan_status,
    ADD COLUMN plan_expires_at TIMESTAMP NULL DEFAULT NULL AFTER plan_activated_at,
    ADD COLUMN asaas_customer_id VARCHAR(100) NULL AFTER plan_expires_at,
    ADD COLUMN asaas_subscription_id VARCHAR(100) NULL AFTER asaas_customer_id;

ALTER TABLE users
    ADD CONSTRAINT fk_users_plan FOREIGN KEY (plan_id) REFERENCES plans(id);

-- 10) ALTER users – cpf (20251128_094500_add_cpf_to_users)
ALTER TABLE users
    ADD COLUMN cpf VARCHAR(20) NULL AFTER email;

-- 11) Índice único em cpf (20251128_100000_add_unique_cpf_to_users)
ALTER TABLE users
    ADD UNIQUE KEY idx_users_cpf_unique (cpf);
