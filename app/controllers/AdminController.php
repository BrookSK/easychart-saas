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

        $currentUserId = $_SESSION['user']['id'] ?? null;

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
                    $ins = $this->pdo->prepare("INSERT INTO api_configs (user_id, provider, api_key) VALUES (:uid, 'asaas_env', :val)");
                    $ins->execute(['uid' => $currentUserId, 'val' => $env]);
                }

                // Salva key sandbox (provider = asaas_sandbox)
                $stmt = $this->pdo->prepare("SELECT id FROM api_configs WHERE provider = 'asaas_sandbox' LIMIT 1");
                $stmt->execute();
                $rowKey = $stmt->fetch();

                if ($rowKey) {
                    $upd = $this->pdo->prepare("UPDATE api_configs SET api_key = :val, updated_at = NOW() WHERE id = :id");
                    $upd->execute(['val' => $sandboxKey, 'id' => $rowKey['id']]);
                } else {
                    $ins = $this->pdo->prepare("INSERT INTO api_configs (user_id, provider, api_key) VALUES (:uid, 'asaas_sandbox', :val)");
                    $ins->execute(['uid' => $currentUserId, 'val' => $sandboxKey]);
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
                        $ins = $this->pdo->prepare("INSERT INTO api_configs (user_id, provider, api_key) VALUES (:uid, 'asaas_production', :val)");
                        $ins->execute(['uid' => $currentUserId, 'val' => $productionKey]);
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

    public function plans()
    {
        $this->requireSuperAdmin();

        $stmt = $this->pdo->query('SELECT * FROM plans ORDER BY price_cents ASC');
        $plans = $stmt->fetchAll();

        require __DIR__ . '/../views/admin/plans_index.php';
    }

    public function editPlan()
    {
        $this->requireSuperAdmin();

        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        $error = '';
        $success = false;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $name   = trim($_POST['name'] ?? '');
            $slug   = trim($_POST['slug'] ?? '');
            $price  = (int)($_POST['price_cents'] ?? 0);
            $spreadLimit = $_POST['monthly_spreadsheet_limit'] !== '' ? (int)$_POST['monthly_spreadsheet_limit'] : null;
            $chartLimit  = $_POST['monthly_chart_limit'] !== '' ? (int)$_POST['monthly_chart_limit'] : null;
            $isActive = isset($_POST['is_active']) ? 1 : 0;

            if ($name === '' || $slug === '') {
                $error = 'Informe nome e slug do plano.';
            } else {
                if ($id > 0) {
                    $stmt = $this->pdo->prepare('UPDATE plans SET name = :name, slug = :slug, price_cents = :price, monthly_spreadsheet_limit = :s_limit, monthly_chart_limit = :c_limit, is_active = :active WHERE id = :id');
                    $stmt->execute([
                        'name'    => $name,
                        'slug'    => $slug,
                        'price'   => $price,
                        's_limit' => $spreadLimit,
                        'c_limit' => $chartLimit,
                        'active'  => $isActive,
                        'id'      => $id,
                    ]);
                } else {
                    $stmt = $this->pdo->prepare('INSERT INTO plans (name, slug, price_cents, monthly_spreadsheet_limit, monthly_chart_limit, is_active) VALUES (:name, :slug, :price, :s_limit, :c_limit, :active)');
                    $stmt->execute([
                        'name'    => $name,
                        'slug'    => $slug,
                        'price'   => $price,
                        's_limit' => $spreadLimit,
                        'c_limit' => $chartLimit,
                        'active'  => $isActive,
                    ]);
                    $id = (int)$this->pdo->lastInsertId();
                }

                $success = true;
            }
        }

        $plan = null;
        if ($id > 0) {
            $stmt = $this->pdo->prepare('SELECT * FROM plans WHERE id = :id');
            $stmt->execute(['id' => $id]);
            $plan = $stmt->fetch();
        }

        require __DIR__ . '/../views/admin/plans_form.php';
    }

    public function deletePlan()
    {
        $this->requireSuperAdmin();

        $id = (int)($_GET['id'] ?? 0);
        if ($id > 0) {
            $stmt = $this->pdo->prepare('DELETE FROM plans WHERE id = :id');
            $stmt->execute(['id' => $id]);
        }

        header('Location: ' . BASE_URL . '?c=admin&a=plans');
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

    public function editUserPlan()
    {
        $this->requireSuperAdmin();

        $id = (int)($_GET['id'] ?? ($_POST['id'] ?? 0));
        if ($id <= 0) {
            header('Location: ' . BASE_URL . '?c=admin&a=index');
            exit;
        }

        $error = '';
        $success = false;

        $stmt = $this->pdo->prepare('SELECT id, full_name, email, plan_id, plan_status, plan_activated_at, plan_expires_at, asaas_subscription_id FROM users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $userPlan = $stmt->fetch();

        if (!$userPlan) {
            header('Location: ' . BASE_URL . '?c=admin&a=index');
            exit;
        }

        $plansStmt = $this->pdo->query('SELECT id, name, slug FROM plans WHERE is_active = 1 ORDER BY price_cents ASC');
        $plans = $plansStmt->fetchAll();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $removePlan = isset($_POST['remove_plan']);

            if ($removePlan) {
                $upd = $this->pdo->prepare('UPDATE users SET plan_id = NULL, plan_status = :status, plan_activated_at = NULL, plan_expires_at = NULL, asaas_subscription_id = NULL WHERE id = :id');
                $upd->execute([
                    'status' => 'free',
                    'id'     => $id,
                ]);
                $success = true;
            } else {
                $planIdRaw = $_POST['plan_id'] ?? '';
                $planId = $planIdRaw !== '' ? (int)$planIdRaw : null;
                $status = $_POST['plan_status'] ?? 'free';

                $allowedStatuses = ['free', 'pending', 'active', 'past_due', 'canceled'];
                if (!in_array($status, $allowedStatuses, true)) {
                    $status = 'free';
                }

                $expiresRaw = trim($_POST['plan_expires_at'] ?? '');
                $expires = $expiresRaw !== '' ? $expiresRaw : null;

                if ($planId === null && $status !== 'free') {
                    $error = 'Selecione um plano ou marque para remover o plano.';
                } else {
                    $upd = $this->pdo->prepare('UPDATE users SET plan_id = :plan_id, plan_status = :status, plan_expires_at = :expires WHERE id = :id');
                    $upd->execute([
                        'plan_id' => $planId,
                        'status'  => $status,
                        'expires' => $expires,
                        'id'      => $id,
                    ]);
                    $success = true;
                }
            }

            if ($success && !$error) {
                $stmt = $this->pdo->prepare('SELECT id, full_name, email, plan_id, plan_status, plan_activated_at, plan_expires_at, asaas_subscription_id FROM users WHERE id = :id LIMIT 1');
                $stmt->execute(['id' => $id]);
                $userPlan = $stmt->fetch();
            }
        }

        require __DIR__ . '/../views/admin/user_plan.php';
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
