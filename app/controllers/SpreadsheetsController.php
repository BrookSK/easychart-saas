<?php

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../core/Database.php';

class SpreadsheetsController
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

        $userId = $_SESSION['user']['id'];
        $error = '';
        $success = '';

        // Upload
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['spreadsheet'])) {
            if ($_FILES['spreadsheet']['error'] === UPLOAD_ERR_OK) {
                $originalName = $_FILES['spreadsheet']['name'];
                $tmpName      = $_FILES['spreadsheet']['tmp_name'];
                $mimeType     = $_FILES['spreadsheet']['type'];
                $sizeBytes    = (int) $_FILES['spreadsheet']['size'];

                $ext = pathinfo($originalName, PATHINFO_EXTENSION);
                $storedName = uniqid('sheet_', true) . '.' . $ext;

                $storageDir = __DIR__ . '/../../storage/spreadsheets';
                if (!is_dir($storageDir)) {
                    mkdir($storageDir, 0777, true);
                }

                $destPath = $storageDir . '/' . $storedName;
                if (move_uploaded_file($tmpName, $destPath)) {
                    $stmt = $this->pdo->prepare('INSERT INTO spreadsheets (user_id, original_name, stored_name, mime_type, size_bytes) VALUES (:user_id, :original_name, :stored_name, :mime_type, :size_bytes)');
                    $stmt->execute([
                        'user_id'       => $userId,
                        'original_name' => $originalName,
                        'stored_name'   => $storedName,
                        'mime_type'     => $mimeType,
                        'size_bytes'    => $sizeBytes,
                    ]);
                    $success = 'Spreadsheet uploaded successfully.';
                } else {
                    $error = 'Failed to save uploaded file.';
                }
            } else {
                $error = 'Upload error code: ' . (int) $_FILES['spreadsheet']['error'];
            }
        }

        // Listagem de planilhas do usuário
        $stmt = $this->pdo->prepare('SELECT * FROM spreadsheets WHERE user_id = :user_id ORDER BY created_at DESC');
        $stmt->execute(['user_id' => $userId]);
        $spreadsheets = $stmt->fetchAll();

        require __DIR__ . '/../views/spreadsheets/index.php';
    }

    public function download()
    {
        $this->requireAuth();

        $userId = $_SESSION['user']['id'];
        $id = (int)($_GET['id'] ?? 0);

        $stmt = $this->pdo->prepare('SELECT * FROM spreadsheets WHERE id = :id AND user_id = :user_id');
        $stmt->execute(['id' => $id, 'user_id' => $userId]);
        $sheet = $stmt->fetch();

        if (!$sheet) {
            http_response_code(404);
            echo 'Spreadsheet not found';
            exit;
        }

        $filePath = __DIR__ . '/../../storage/spreadsheets/' . $sheet['stored_name'];
        if (!is_file($filePath)) {
            http_response_code(404);
            echo 'File not found on disk';
            exit;
        }

        header('Content-Type: ' . $sheet['mime_type']);
        header('Content-Disposition: attachment; filename="' . basename($sheet['original_name']) . '"');
        header('Content-Length: ' . filesize($filePath));
        readfile($filePath);
        exit;
    }

    public function delete()
    {
        $this->requireAuth();

        $userId = $_SESSION['user']['id'];
        $id = (int)($_GET['id'] ?? 0);

        $stmt = $this->pdo->prepare('SELECT * FROM spreadsheets WHERE id = :id AND user_id = :user_id');
        $stmt->execute(['id' => $id, 'user_id' => $userId]);
        $sheet = $stmt->fetch();

        if ($sheet) {
            $filePath = __DIR__ . '/../../storage/spreadsheets/' . $sheet['stored_name'];
            if (is_file($filePath)) {
                unlink($filePath);
            }

            $del = $this->pdo->prepare('DELETE FROM spreadsheets WHERE id = :id AND user_id = :user_id');
            $del->execute(['id' => $id, 'user_id' => $userId]);
        }

        header('Location: ' . BASE_URL . '?c=spreadsheets&a=index');
        exit;
    }
}
