-- Migration: persistência de overrides (desambiguação) para aprendizado contextual

CREATE TABLE IF NOT EXISTS analysis_overrides (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    spreadsheet_id INT UNSIGNED NOT NULL,
    overrides_json LONGTEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL,

    KEY idx_analysis_overrides_user_sheet (user_id, spreadsheet_id),
    KEY idx_analysis_overrides_user_created (user_id, created_at),

    CONSTRAINT fk_analysis_overrides_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_analysis_overrides_spreadsheet FOREIGN KEY (spreadsheet_id) REFERENCES spreadsheets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
