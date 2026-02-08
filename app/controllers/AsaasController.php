<?php

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../helpers/AsaasClient.php';

class AsaasController
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getConnection();
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    private function requireAuth()
    {
        if (empty($_SESSION['user'])) {
            header('Location: ' . BASE_URL . '?c=auth&a=login');
            exit;
        }
    }

    public function subscribePremium()
    {
        http_response_code(404);
        echo 'Not found';
        exit;
    }
}
