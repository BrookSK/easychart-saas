-- Migration: persistência de análises e relatórios técnicos (ETAPAS 1-9)

CREATE TABLE IF NOT EXISTS analysis_reports (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    spreadsheet_id INT UNSIGNED NULL,
    user_request TEXT NULL,

    -- ETAPA 1 (perfil do dataset)
    dataset_profile_json LONGTEXT NULL,

    -- ETAPA 2 (contexto inferido)
    inferred_context_json LONGTEXT NULL,

    -- ETAPAS 3/4/8 (modelo analítico, estatísticas, tendências)
    analytics_json LONGTEXT NULL,

    -- ETAPA 5 (gráficos aplicáveis)
    charts_json LONGTEXT NULL,

    -- ETAPA 9 (relatório final no formato fixo)
    report_text LONGTEXT NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL,

    KEY idx_analysis_reports_user_created (user_id, created_at),
    KEY idx_analysis_reports_spreadsheet (spreadsheet_id),
    CONSTRAINT fk_analysis_reports_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_analysis_reports_spreadsheet FOREIGN KEY (spreadsheet_id) REFERENCES spreadsheets(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
