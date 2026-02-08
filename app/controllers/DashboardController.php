<?php

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../helpers/DataIngestionHelper.php';
require_once __DIR__ . '/../helpers/AnalysisEngine.php';
require_once __DIR__ . '/../helpers/OpenAIClient.php';

class DashboardController
{
    public function index()
    {
        if (empty($_SESSION['user'])) {
            header('Location: ' . BASE_URL . '?c=auth&a=login');
            exit;
        }

        $user = $_SESSION['user'];

        $pdo = Database::getConnection();

        $error = '';
        $success = '';
        $lastChartResponse = null;
        // Agora suportamos múltiplos gráficos por requisição
        $chartsData = [];
        $analysisReportText = null;
        $analysisReportId = null;
        $clarificationQuestions = [];
        $decisionLog = null;

        // Trata envio do AI Chart Generator
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $prompt        = trim($_POST['prompt'] ?? '');
            $spreadsheetId = (int)($_POST['spreadsheet_id'] ?? 0);

            $overrides = [];
            if (isset($_POST['overrides']) && is_array($_POST['overrides'])) {
                $overrides = $_POST['overrides'];
            }

            if (empty($overrides['__last_request_hash']) && !empty($_SESSION['analysis_last_request_hash'])) {
                $overrides['__last_request_hash'] = (string)$_SESSION['analysis_last_request_hash'];
            }
            if (empty($overrides['__last_plan_sig']) && !empty($_SESSION['analysis_last_plan_sig'])) {
                $overrides['__last_plan_sig'] = (string)$_SESSION['analysis_last_plan_sig'];
            }
            $skipClarification = !empty($_POST['skip_clarification']);
            if ($skipClarification) {
                $overrides['skip_clarification'] = true;
            }

            // Upload opcional de novo arquivo direto pelo dashboard
            if (isset($_FILES['spreadsheet']) && $_FILES['spreadsheet']['error'] === UPLOAD_ERR_OK) {
                if (!$error) {
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
                        $stmt = $pdo->prepare('INSERT INTO spreadsheets (user_id, original_name, stored_name, mime_type, size_bytes) VALUES (:user_id, :original_name, :stored_name, :mime_type, :size_bytes)');
                        $stmt->execute([
                            'user_id'       => $user['id'],
                            'original_name' => $originalName,
                            'stored_name'   => $storedName,
                            'mime_type'     => $mimeType,
                            'size_bytes'    => $sizeBytes,
                        ]);
                        $spreadsheetId = (int)$pdo->lastInsertId();
                    } else {
                        $error = 'Failed to save uploaded spreadsheet.';
                    }
                }
            }

            if (!$error) {
                if ($spreadsheetId <= 0) {
                    $error = 'Please select or upload a file.';
                } elseif ($prompt === '') {
                    $error = 'Please describe what you want to visualize.';
                } else {
                    // Descobre caminho do arquivo selecionado
                    $sheetStmt = $pdo->prepare('SELECT stored_name, original_name, mime_type, size_bytes FROM spreadsheets WHERE id = :id AND user_id = :uid');
                    $sheetStmt->execute(['id' => $spreadsheetId, 'uid' => $user['id']]);
                    $sheet = $sheetStmt->fetch();

                    if ($sheet) {
                        $filePath = __DIR__ . '/../../storage/spreadsheets/' . $sheet['stored_name'];

                        $ing = DataIngestionHelper::ingestFile($filePath, (string)$sheet['original_name']);
                        if (empty($ing['ok']) || empty($ing['table'])) {
                            $aiPayload = [
                                'status' => 'error',
                                'error'  => $ing['error'] ?? 'Falha ao extrair dados do arquivo.',
                            ];
                            $error = $aiPayload['error'];
                        } else {
                            // Aplica overrides persistidos (aprendizado contextual) antes do processamento
                            try {
                                $ovStmt = $pdo->prepare('SELECT overrides_json FROM analysis_overrides WHERE user_id = :uid AND spreadsheet_id = :sid ORDER BY updated_at DESC, created_at DESC LIMIT 1');
                                $ovStmt->execute(['uid' => (int)$user['id'], 'sid' => (int)$spreadsheetId]);
                                $ovRow = $ovStmt->fetch();
                                if ($ovRow && !empty($ovRow['overrides_json'])) {
                                    $persisted = json_decode((string)$ovRow['overrides_json'], true);
                                    if (is_array($persisted)) {
                                        // POST tem precedência
                                        $overrides = array_merge($persisted, $overrides);
                                    }
                                }
                            } catch (PDOException $e) {
                                // Se a tabela não existir ainda, seguimos sem aprendizado.
                            }

                            $openAiKey = '';
                            try {
                                $kStmt = $pdo->prepare("SELECT api_key FROM api_configs WHERE user_id = :uid AND provider = 'openai' LIMIT 1");
                                $kStmt->execute(['uid' => (int)$user['id']]);
                                $kRow = $kStmt->fetch();
                                if ($kRow && !empty($kRow['api_key'])) {
                                    $openAiKey = (string)$kRow['api_key'];
                                }
                            } catch (PDOException $e) {
                                $openAiKey = '';
                            }

                            $llmConfig = [
                                'provider' => 'openai',
                                'api_key' => $openAiKey,
                                'model' => 'gpt-4o-mini',
                            ];

                            $result = AnalysisEngine::run($ing['table'], $prompt, $overrides, $llmConfig);

                            $decisionLog = $result['decision_log'] ?? null;

                            $needsClarification = !empty($result['needs_clarification']);
                            if ($needsClarification) {
                                $clarificationQuestions = $result['questions'] ?? [];
                            }

                            if (!$needsClarification) {
                                $rp = $result['request_plan'] ?? null;
                                if (is_array($rp)) {
                                    if (!empty($rp['request_hash'])) {
                                        $_SESSION['analysis_last_request_hash'] = (string)$rp['request_hash'];
                                    }
                                    if (!empty($rp['plan_signature'])) {
                                        $_SESSION['analysis_last_plan_sig'] = (string)$rp['plan_signature'];
                                    }
                                }
                            }

                            $chartsList = $result['charts'] ?? [];
                            $aiPayload = [
                                'status' => 'ok',
                                'charts' => $chartsList,
                                'request_plan' => $result['request_plan'] ?? null,
                                'inferred_context' => $result['inferred_context'] ?? null,
                                'needs_clarification' => $result['needs_clarification'] ?? false,
                                'questions' => $result['questions'] ?? [],
                            ];

                            if ($needsClarification) {
                                // Não persiste relatório/gráficos até desambiguar.
                                $analysisReportText = null;
                                $analysisReportId = null;
                            } else {

                                $analysisReportText = $result['report_text'] ?? null;
                                $analysisReportHtml = $result['report_html'] ?? null;
                                if (!empty($analysisReportHtml)) {
                                    $analysisReportText = $analysisReportHtml;
                                }

                                $datasetProfileToStore = $result['dataset_profile'] ?? null;
                                if (is_array($datasetProfileToStore) && array_key_exists('sample_rows', $datasetProfileToStore)) {
                                    unset($datasetProfileToStore['sample_rows']);
                                }

                                $ins = $pdo->prepare('INSERT INTO analysis_reports (user_id, spreadsheet_id, user_request, dataset_profile_json, inferred_context_json, analytics_json, charts_json, report_text) VALUES (:uid, :sid, :req, :dp, :ic, :an, :cj, :rt)');
                                try {
                                    $ins->execute([
                                        'uid' => (int)$user['id'],
                                        'sid' => (int)$spreadsheetId,
                                        'req' => $prompt,
                                        'dp'  => json_encode($datasetProfileToStore, JSON_UNESCAPED_UNICODE),
                                        'ic'  => json_encode($result['inferred_context'] ?? null, JSON_UNESCAPED_UNICODE),
                                        'an'  => json_encode($result['analytics'] ?? null, JSON_UNESCAPED_UNICODE),
                                        'cj'  => json_encode($chartsList, JSON_UNESCAPED_UNICODE),
                                        'rt'  => $analysisReportHtml ?: $analysisReportText,
                                    ]);
                                    $analysisReportId = (int)$pdo->lastInsertId();
                                } catch (PDOException $e) {
                                    $msg = $e->getMessage();
                                    $isMissingTable = (strpos($msg, 'SQLSTATE[42S02]') !== false) || (strpos($msg, "doesn't exist") !== false);
                                    $mentionsTable = (strpos($msg, 'analysis_reports') !== false);
                                    if ($isMissingTable && $mentionsTable) {
                                        $error = 'Missing database table `analysis_reports`. Please run migration: database/20260207_000002_create_analysis_reports.sql';
                                    } else {
                                        throw $e;
                                    }
                                }

                                // Persiste overrides informados pelo usuário para reutilização futura
                                $toPersist = [];
                                foreach (['amount_column', 'date_column', 'category_column', 'finance_mode'] as $k) {
                                    if (isset($overrides[$k]) && trim((string)$overrides[$k]) !== '') {
                                        $toPersist[$k] = trim((string)$overrides[$k]);
                                    }
                                }
                                if (!empty($toPersist)) {
                                    try {
                                        $up = $pdo->prepare('INSERT INTO analysis_overrides (user_id, spreadsheet_id, overrides_json) VALUES (:uid, :sid, :oj)');
                                        $up->execute([
                                            'uid' => (int)$user['id'],
                                            'sid' => (int)$spreadsheetId,
                                            'oj' => json_encode($toPersist, JSON_UNESCAPED_UNICODE),
                                        ]);
                                    } catch (PDOException $e) {
                                        // tabela pode não existir ainda; ignore.
                                    }
                                }
                            }
                        }
                    }

                    // Salva registro(s) de gráfico com payload (stub ou IA real)
                    if (empty($aiPayload['needs_clarification'])) {
                        if ($aiPayload['status'] === 'ok' && !empty($aiPayload['charts'])) {
                            $stmt = $pdo->prepare('INSERT INTO charts (user_id, spreadsheet_id, prompt, chart_type, data_json) VALUES (:user_id, :spreadsheet_id, :prompt, :chart_type, :data_json)');

                            foreach ($aiPayload['charts'] as $chartConfig) {
                                $stmt->execute([
                                    'user_id'        => $user['id'],
                                    'spreadsheet_id' => $spreadsheetId,
                                    'prompt'         => $prompt,
                                    'chart_type'     => $chartConfig['chart_type'] ?? null,
                                    'data_json'      => json_encode($chartConfig),
                                ]);

                                // Prepara dados para renderização no frontend
                                $labels = isset($chartConfig['labels']) && is_array($chartConfig['labels']) ? $chartConfig['labels'] : [];
                                $valuesRaw = isset($chartConfig['values']) && is_array($chartConfig['values']) ? $chartConfig['values'] : [];
                                $values = [];
                                foreach ($valuesRaw as $v) {
                                    $values[] = (float)$v;
                                }

                                if ($labels && $values && count($labels) === count($values)) {
                                    $rawType = $chartConfig['chart_type'] ?? 'line';
                                    $renderType = $rawType;
                                    if (in_array($rawType, ['boxplot', 'gantt'], true)) {
                                        $renderType = 'bar';
                                    }
                                    $chartsData[] = [
                                        'type'   => $renderType,
                                        'title'  => $chartConfig['title'] ?? 'Generated chart',
                                        'description' => $chartConfig['description'] ?? null,
                                        'insight' => $chartConfig['insight'] ?? null,
                                        'caveat' => $chartConfig['caveat'] ?? null,
                                        'labels' => $labels,
                                        'values' => $values,
                                    ];
                                }
                            }
                        } else {
                            // Mesmo em modo stub, salvamos um registro simples para manter histórico
                            $stmt = $pdo->prepare('INSERT INTO charts (user_id, spreadsheet_id, prompt, chart_type, data_json) VALUES (:user_id, :spreadsheet_id, :prompt, :chart_type, :data_json)');
                            $stmt->execute([
                                'user_id'        => $user['id'],
                                'spreadsheet_id' => $spreadsheetId,
                                'prompt'         => $prompt,
                                'chart_type'     => null,
                                'data_json'      => json_encode($aiPayload),
                            ]);
                        }
                    }

                    $lastChartResponse = $aiPayload;
                    if (isset($result) && is_array($result)) {
                        $lastChartResponse['report_html'] = $result['report_html'] ?? null;
                        $lastChartResponse['report_text'] = $result['report_text'] ?? null;
                        $lastChartResponse['stages'] = $result['stages'] ?? null;
                        $lastChartResponse['dashboard_plan'] = $result['dashboard_plan'] ?? null;
                        $lastChartResponse['decision_log'] = $result['decision_log'] ?? null;
                    }

                    if (!$error) {
                        if (!empty($aiPayload['needs_clarification'])) {
                            $success = 'Need clarification to improve accuracy.';
                        } elseif ($aiPayload['status'] === 'ok') {
                            $success = 'Analysis generated successfully.';
                        } else {
                            $success = 'Analysis generated (stub).';
                        }
                    }
                }
            }
        }

        // Métricas simples para os cards
        $stmt = $pdo->query('SELECT COUNT(*) AS c FROM spreadsheets');
        $totalSpreadsheets = (int)$stmt->fetch()['c'];

        $stmt = $pdo->query('SELECT COUNT(*) AS c FROM charts');
        $totalCharts = (int)$stmt->fetch()['c'];

        // Por enquanto, consideramos cada chart gerado como um "Saved Dashboard"
        $savedDashboards = $totalCharts;
        $aiInsights      = $totalCharts; // aproximar insights de charts

        // Planilhas do usuário para o select
        $stmt = $pdo->prepare('SELECT id, original_name FROM spreadsheets WHERE user_id = :uid ORDER BY created_at DESC');
        $stmt->execute(['uid' => $user['id']]);
        $spreadsheets = $stmt->fetchAll();

        require __DIR__ . '/../views/dashboard/index.php';
    }

    public function logout()
    {
        $_SESSION = [];
        session_destroy();
        header('Location: ' . BASE_URL . '?c=auth&a=login');
        exit;
    }
}
