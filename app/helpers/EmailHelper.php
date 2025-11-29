<?php

require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../config/config.php';

class EmailHelper
{
    public static function send(string $to, string $subject, string $message): bool
    {
        $pdo = Database::getConnection();

        $stmt = $pdo->query('SELECT * FROM email_settings ORDER BY id ASC LIMIT 1');
        $settings = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        $fromEmail = $settings['from_email'] ?? 'no-reply@localhost';
        $fromName  = $settings['from_name'] ?? 'EasyChart';

        $headers  = 'From: ' . sprintf('"%s" <%s>', $fromName, $fromEmail) . "\r\n";
        $headers .= 'Reply-To: ' . $fromEmail . "\r\n";
        $headers .= 'Content-Type: text/plain; charset=UTF-8' . "\r\n";

        // Por enquanto, usar mail() simples. Configurações SMTP podem ser usadas futuramente.
        return mail($to, $subject, $message, $headers);
    }
}
