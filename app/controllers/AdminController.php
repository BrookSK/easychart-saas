<?php

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../core/Database.php';

class AdminController
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getConnection();
    }

    private function requireSuperAdmin()
    {
        if (empty($_SESSION['user'])) {
            header('Location: ' . BASE_URL . '?c=auth&a=login');
            exit;
        }

        if (($_SESSION['user']['role'] ?? 'user') !== 'super_admin') {
            http_response_code(403);
            echo 'Access denied: admin only.';
            exit;
        }
    }

    public function index()
    {
        $this->requireSuperAdmin();

        $user = $_SESSION['user'];

        // Metrics
        $stmt = $this->pdo->query('SELECT COUNT(*) AS c FROM users');
        $totalUsers = (int)$stmt->fetch()['c'];

        $stmt = $this->pdo->query('SELECT COUNT(*) AS c FROM users WHERE last_login_at IS NOT NULL');
        $activeUsers = (int)$stmt->fetch()['c'];

        $stmt = $this->pdo->query('SELECT COUNT(*) AS c FROM charts');
        $totalCharts = (int)$stmt->fetch()['c'];

        $stmt = $this->pdo->query('SELECT COUNT(*) AS c FROM spreadsheets');
        $totalSpreadsheets = (int)$stmt->fetch()['c'];

        // User management list
        $sql = 'SELECT u.id, u.full_name, u.email, u.role, u.is_active, u.created_at,
                       COALESCE(c.c_charts, 0) AS charts_count,
                       COALESCE(s.c_sheets, 0) AS sheets_count
                FROM users u
                LEFT JOIN (
                    SELECT user_id, COUNT(*) AS c_charts FROM charts GROUP BY user_id
                ) c ON c.user_id = u.id
                LEFT JOIN (
                    SELECT user_id, COUNT(*) AS c_sheets FROM spreadsheets GROUP BY user_id
                ) s ON s.user_id = u.id
                ORDER BY u.created_at DESC';
        $stmt = $this->pdo->query($sql);
        $users = $stmt->fetchAll();

        require __DIR__ . '/../views/admin/index.php';
    }

    public function emailSettings()
    {
        $this->requireSuperAdmin();

        $error = '';
        $success = false;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $smtpHost = trim($_POST['smtp_host'] ?? '');
            $smtpPort = (int)($_POST['smtp_port'] ?? 0);
            $smtpUser = trim($_POST['smtp_user'] ?? '');
            $smtpPassword = trim($_POST['smtp_password'] ?? '');
            $fromEmail = trim($_POST['from_email'] ?? '');
            $fromName  = trim($_POST['from_name'] ?? '');
            $useSmtp   = isset($_POST['use_smtp']) ? 1 : 0;

            if ($fromEmail === '' || $fromName === '') {
                $error = 'Informe pelo menos o e-mail e o nome do remetente.';
            } else {
                $stmt = $this->pdo->query('SELECT id FROM email_settings ORDER BY id ASC LIMIT 1');
                $settings = $stmt->fetch();

                if ($settings) {
                    $upd = $this->pdo->prepare('UPDATE email_settings SET smtp_host = :host, smtp_port = :port, smtp_user = :user, smtp_password = :pass, from_email = :from_email, from_name = :from_name, use_smtp = :use_smtp, updated_at = NOW() WHERE id = :id');
                    $upd->execute([
                        'host'       => $smtpHost,
                        'port'       => $smtpPort,
                        'user'       => $smtpUser,
                        'pass'       => $smtpPassword,
                        'from_email' => $fromEmail,
                        'from_name'  => $fromName,
                        'use_smtp'   => $useSmtp,
                        'id'         => $settings['id'],
                    ]);
                } else {
                    $ins = $this->pdo->prepare('INSERT INTO email_settings (smtp_host, smtp_port, smtp_user, smtp_password, from_email, from_name, use_smtp) VALUES (:host, :port, :user, :pass, :from_email, :from_name, :use_smtp)');
                    $ins->execute([
                        'host'       => $smtpHost,
                        'port'       => $smtpPort,
                        'user'       => $smtpUser,
                        'pass'       => $smtpPassword,
                        'from_email' => $fromEmail,
                        'from_name'  => $fromName,
                        'use_smtp'   => $useSmtp,
                    ]);
                }

                $success = true;
            }
        }

        $stmt = $this->pdo->query('SELECT * FROM email_settings ORDER BY id ASC LIMIT 1');
        $settings = $stmt->fetch() ?: [
            'smtp_host'     => '',
            'smtp_port'     => 587,
            'smtp_user'     => '',
            'smtp_password' => '',
            'from_email'    => '',
            'from_name'     => 'EasyChart',
            'use_smtp'      => 0,
        ];

        require __DIR__ . '/../views/admin/email_settings.php';
    }

    public function asaasSettings()
    {
        $this->requireSuperAdmin();

        $error = '';
        $success = false;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $env = trim($_POST['asaas_env'] ?? 'sandbox');
            $sandboxKey = trim($_POST['asaas_sandbox_key'] ?? '');
            $productionKey = trim($_POST['asaas_production_key'] ?? '');

            if ($sandboxKey === '' && $productionKey === '') {
                $error = 'Informe pelo menos a API key de Sandbox ou de Produção do Asaas.';
            } else {
                // Salva ambiente em api_configs (provider = asaas_env)
                $stmt = $this->pdo->prepare("SELECT id FROM api_configs WHERE provider = 'asaas_env' LIMIT 1");
                $stmt->execute();
                $rowEnv = $stmt->fetch();

                if ($rowEnv) {
                    $upd = $this->pdo->prepare("UPDATE api_configs SET api_key = :val, updated_at = NOW() WHERE id = :id");
                    $upd->execute(['val' => $env, 'id' => $rowEnv['id']]);
                } else {
                    $ins = $this->pdo->prepare("INSERT INTO api_configs (user_id, provider, api_key) VALUES (NULL, 'asaas_env', :val)");
                    $ins->execute(['val' => $env]);
                }

                // Salva key sandbox (provider = asaas_sandbox)
                $stmt = $this->pdo->prepare("SELECT id FROM api_configs WHERE provider = 'asaas_sandbox' LIMIT 1");
                $stmt->execute();
                $rowKey = $stmt->fetch();

                if ($rowKey) {
                    $upd = $this->pdo->prepare("UPDATE api_configs SET api_key = :val, updated_at = NOW() WHERE id = :id");
                    $upd->execute(['val' => $sandboxKey, 'id' => $rowKey['id']]);
                } else {
                    $ins = $this->pdo->prepare("INSERT INTO api_configs (user_id, provider, api_key) VALUES (NULL, 'asaas_sandbox', :val)");
                    $ins->execute(['val' => $sandboxKey]);
                }

                // Salva key produção (provider = asaas_production)
                $stmt = $this->pdo->prepare("SELECT id FROM api_configs WHERE provider = 'asaas_production' LIMIT 1");
                $stmt->execute();
                $rowKeyProd = $stmt->fetch();

                if ($rowKeyProd) {
                    $upd = $this->pdo->prepare("UPDATE api_configs SET api_key = :val, updated_at = NOW() WHERE id = :id");
                    $upd->execute(['val' => $productionKey, 'id' => $rowKeyProd['id']]);
                } else {
                    if ($productionKey !== '') {
                        $ins = $this->pdo->prepare("INSERT INTO api_configs (user_id, provider, api_key) VALUES (NULL, 'asaas_production', :val)");
                        $ins->execute(['val' => $productionKey]);
                    }
                }

                $success = true;
            }
        }

        // Carrega valores atuais
        $envValue = 'sandbox';
        $sandboxKeyValue = '';
        $productionKeyValue = '';

        $stmt = $this->pdo->prepare("SELECT provider, api_key FROM api_configs WHERE provider IN ('asaas_env','asaas_sandbox','asaas_production')");
        $stmt->execute();
        $rows = $stmt->fetchAll();
        foreach ($rows as $row) {
            if ($row['provider'] === 'asaas_env') {
                $envValue = $row['api_key'] ?: 'sandbox';
            } elseif ($row['provider'] === 'asaas_sandbox') {
                $sandboxKeyValue = $row['api_key'];
            } elseif ($row['provider'] === 'asaas_production') {
                $productionKeyValue = $row['api_key'];
            }
        }

        $settings = [
            'asaas_env'         => $envValue,
            'asaas_sandbox_key' => $sandboxKeyValue,
            'asaas_production_key' => $productionKeyValue,
        ];

        require __DIR__ . '/../views/admin/asaas_settings.php';
    }

    public function toggleRole()
    {
        $this->requireSuperAdmin();

        $currentUserId = $_SESSION['user']['id'];
        $id = (int)($_GET['id'] ?? 0);

        if ($id <= 0 || $id === $currentUserId) {
            header('Location: ' . BASE_URL . '?c=admin&a=index');
            exit;
        }

        $stmt = $this->pdo->prepare('SELECT role FROM users WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        if ($row) {
            $newRole = $row['role'] === 'super_admin' ? 'user' : 'super_admin';
            $upd = $this->pdo->prepare("UPDATE users SET role = :role WHERE id = :id");
            $upd->execute(['role' => $newRole, 'id' => $id]);
        }

        header('Location: ' . BASE_URL . '?c=admin&a=index');
        exit;
    }

    public function toggleStatus()
    {
        $this->requireSuperAdmin();

        $currentUserId = $_SESSION['user']['id'];
        $id = (int)($_GET['id'] ?? 0);

        if ($id <= 0 || $id === $currentUserId) {
            header('Location: ' . BASE_URL . '?c=admin&a=index');
            exit;
        }

        $stmt = $this->pdo->prepare('SELECT is_active FROM users WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        if ($row) {
            $newStatus = (int)!((int)$row['is_active']);
            $upd = $this->pdo->prepare('UPDATE users SET is_active = :s WHERE id = :id');
            $upd->execute(['s' => $newStatus, 'id' => $id]);
        }

        header('Location: ' . BASE_URL . '?c=admin&a=index');
        exit;
    }

    public function delete()
    {
        $this->requireSuperAdmin();

        $currentUserId = $_SESSION['user']['id'];
        $id = (int)($_GET['id'] ?? 0);

        if ($id <= 0 || $id === $currentUserId) {
            header('Location: ' . BASE_URL . '?c=admin&a=index');
            exit;
        }

        $del = $this->pdo->prepare('DELETE FROM users WHERE id = :id');
        $del->execute(['id' => $id]);

        header('Location: ' . BASE_URL . '?c=admin&a=index');
        exit;
    }

    public function view()
    {
        $this->requireSuperAdmin();

        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) {
            header('Location: ' . BASE_URL . '?c=admin&a=index');
            exit;
        }

        $sql = 'SELECT u.id, u.full_name, u.email, u.role, u.is_active, u.created_at, u.last_login_at,
                       COALESCE(c.c_charts, 0) AS charts_count,
                       COALESCE(s.c_sheets, 0) AS sheets_count
                FROM users u
                LEFT JOIN (
                    SELECT user_id, COUNT(*) AS c_charts FROM charts GROUP BY user_id
                ) c ON c.user_id = u.id
                LEFT JOIN (
                    SELECT user_id, COUNT(*) AS c_sheets FROM spreadsheets GROUP BY user_id
                ) s ON s.user_id = u.id
                WHERE u.id = :id
                LIMIT 1';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['id' => $id]);
        $userDetail = $stmt->fetch();

        if (!$userDetail) {
            header('Location: ' . BASE_URL . '?c=admin&a=index');
            exit;
        }

        require __DIR__ . '/../views/admin/user.php';
    }
}
