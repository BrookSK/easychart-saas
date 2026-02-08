<?php

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../core/Database.php';

class SettingsController
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getConnection();
    }

    private function requireAuth()
    {
        if (empty($_SESSION['user'])) {
            header('Location: ' . BASE_URL . '?c=auth&a=login');
            exit;
        }
    }

    public function index()
    {
        $this->requireAuth();

        $user = $_SESSION['user'];
        $userId = $user['id'];

        $error = '';
        $success = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $fullName = trim($_POST['full_name'] ?? '');
            $email    = trim($_POST['email'] ?? '');

            $notifyEmail   = isset($_POST['notify_email']) ? 1 : 0;
            $notifyWeekly  = isset($_POST['notify_weekly']) ? 1 : 0;
            $notifyProduct = isset($_POST['notify_product']) ? 1 : 0;

            $apiKey = trim($_POST['api_key'] ?? '');

            if ($fullName === '') {
                $error = 'Full name is required.';
            } else {
                // Atualiza nome (e email, se quiser) na tabela users
                $stmt = $this->pdo->prepare('UPDATE users SET full_name = :full_name WHERE id = :id');
                $stmt->execute([
                    'full_name' => $fullName,
                    'id'        => $userId,
                ]);

                $_SESSION['user']['full_name'] = $fullName;

                // Upsert em user_settings
                $stmt = $this->pdo->prepare('SELECT id FROM user_settings WHERE user_id = :uid LIMIT 1');
                $stmt->execute(['uid' => $userId]);
                $settings = $stmt->fetch();

                if ($settings) {
                    $stmt = $this->pdo->prepare('UPDATE user_settings SET notification_email_enabled = :ne, notification_weekly_summary = :nw, notification_product_updates = :np, updated_at = NOW() WHERE user_id = :uid');
                } else {
                    $stmt = $this->pdo->prepare('INSERT INTO user_settings (user_id, notification_email_enabled, notification_weekly_summary, notification_product_updates) VALUES (:uid, :ne, :nw, :np)');
                }

                $stmt->execute([
                    'uid' => $userId,
                    'ne'  => $notifyEmail,
                    'nw'  => $notifyWeekly,
                    'np'  => $notifyProduct,
                ]);

                // API config (simples, 1 config por usuário para provider 'openai')
                if ($apiKey !== '') {
                    $stmt = $this->pdo->prepare("SELECT id FROM api_configs WHERE user_id = :uid AND provider = 'openai' LIMIT 1");
                    $stmt->execute(['uid' => $userId]);
                    $api = $stmt->fetch();

                    if ($api) {
                        $stmt = $this->pdo->prepare("UPDATE api_configs SET api_key = :key, updated_at = NOW() WHERE id = :id");
                        $stmt->execute(['key' => $apiKey, 'id' => $api['id']]);
                    } else {
                        $stmt = $this->pdo->prepare("INSERT INTO api_configs (user_id, provider, api_key) VALUES (:uid, 'openai', :key)");
                        $stmt->execute(['uid' => $userId, 'key' => $apiKey]);
                    }
                }

                $success = 'Settings saved successfully.';
            }
        }

        // Carrega dados atuais
        $stmt = $this->pdo->prepare('SELECT full_name, email FROM users WHERE id = :id');
        $stmt->execute(['id' => $userId]);
        $userRow = $stmt->fetch();

        $stmt = $this->pdo->prepare('SELECT * FROM user_settings WHERE user_id = :uid');
        $stmt->execute(['uid' => $userId]);
        $userSettings = $stmt->fetch() ?: [
            'notification_email_enabled'   => 1,
            'notification_weekly_summary'  => 1,
            'notification_product_updates' => 1,
        ];

        $stmt = $this->pdo->prepare("SELECT api_key FROM api_configs WHERE user_id = :uid AND provider = 'openai' LIMIT 1");
        $stmt->execute(['uid' => $userId]);
        $apiConfig = $stmt->fetch();

        $apiKeyValue = $apiConfig ? $apiConfig['api_key'] : '';

        require __DIR__ . '/../views/settings/index.php';
    }
}
