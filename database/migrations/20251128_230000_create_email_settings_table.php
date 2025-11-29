<?php

require_once __DIR__ . '/../../app/core/Database.php';

class CreateEmailSettingsTable
{
    public static function up()
    {
        $pdo = Database::getConnection();

        $sql = "
            CREATE TABLE IF NOT EXISTS email_settings (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                smtp_host VARCHAR(255) NOT NULL,
                smtp_port INT UNSIGNED NOT NULL,
                smtp_user VARCHAR(255) NOT NULL,
                smtp_password VARCHAR(255) NOT NULL,
                from_email VARCHAR(255) NOT NULL,
                from_name VARCHAR(255) NOT NULL,
                use_smtp TINYINT(1) NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ";

        $pdo->exec($sql);
    }

    public static function down()
    {
        $pdo = Database::getConnection();
        $pdo->exec('DROP TABLE IF EXISTS email_settings;');
    }
}
