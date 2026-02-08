-- Migration: adiciona limite mensal de tokens por plano e rastreio de consumo mensal por usuário

-- 1) Campo de limite de tokens no plano (NULL = ilimitado)
ALTER TABLE plans
    ADD COLUMN monthly_token_limit INT NULL AFTER monthly_chart_limit;

-- 2) Tabela de consumo mensal de tokens por usuário (acumulado)
CREATE TABLE IF NOT EXISTS user_token_usage_monthly (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    year_month CHAR(7) NOT NULL, -- formato YYYY-MM
    tokens_used BIGINT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL,
    UNIQUE KEY uniq_user_month (user_id, year_month),
    CONSTRAINT fk_user_token_usage_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3) Ajuste inicial sugerido dos limites (idempotente)
-- Free: 20k tokens/mês | Premium: ilimitado
UPDATE plans SET monthly_token_limit = 20000 WHERE slug = 'free' AND (monthly_token_limit IS NULL OR monthly_token_limit = 0);
UPDATE plans SET monthly_token_limit = NULL WHERE slug = 'premium';
