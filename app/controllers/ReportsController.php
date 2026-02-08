<?php

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../core/Database.php';

class ReportsController
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getConnection();
    }

    private function requireAuth(): void
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
        $stmt = $this->pdo->prepare('SELECT r.id, r.created_at, r.user_request, s.original_name AS spreadsheet_name FROM analysis_reports r LEFT JOIN spreadsheets s ON r.spreadsheet_id = s.id WHERE r.user_id = :uid ORDER BY r.created_at DESC');
        $stmt->execute(['uid' => (int)$user['id']]);
        $reports = $stmt->fetchAll();

        require __DIR__ . '/../views/reports/index.php';
    }

    public function view()
    {
        $this->requireAuth();

        $user = $_SESSION['user'];
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) {
            header('Location: ' . BASE_URL . '?c=reports&a=index');
            exit;
        }

        $stmt = $this->pdo->prepare('SELECT r.*, s.original_name AS spreadsheet_name FROM analysis_reports r LEFT JOIN spreadsheets s ON r.spreadsheet_id = s.id WHERE r.id = :id AND r.user_id = :uid LIMIT 1');
        $stmt->execute(['id' => $id, 'uid' => (int)$user['id']]);
        $report = $stmt->fetch();

        if (!$report) {
            http_response_code(404);
            echo 'Report not found';
            exit;
        }

        $charts = [];
        if (!empty($report['charts_json'])) {
            $decoded = json_decode((string)$report['charts_json'], true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $charts = $decoded;
            }
        }

        require __DIR__ . '/../views/reports/view.php';
    }
}
