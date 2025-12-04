<?php

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../helpers/PlanHelper.php';

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
        // Agora suportamos múltiplos gráficos e insights textuais por requisição
        $chartsData = [];
        $insightsData = [];
        $duplicateSpreadsheetMsg = '';

        // Flags iniciais de plano para controlar UI
        [$canUploadInitial, $planUploadErrorUpload] = PlanHelper::canUploadSpreadsheet($pdo, (int)$user['id']);
        [$canGenerateInitial, $planChartErrorInitial] = PlanHelper::canGenerateCharts($pdo, (int)$user['id'], 1);
        $planUploadLocked = !$canUploadInitial;
        $planChartsLocked = !$canGenerateInitial;

        // Trata envio do AI Chart Generator
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $prompt        = trim($_POST['prompt'] ?? '');
            $spreadsheetId = (int)($_POST['spreadsheet_id'] ?? 0);

            // Upload opcional de novo arquivo direto pelo dashboard
            if (isset($_FILES['spreadsheet']) && $_FILES['spreadsheet']['error'] === UPLOAD_ERR_OK) {
                $originalName = $_FILES['spreadsheet']['name'];
                $tmpName      = $_FILES['spreadsheet']['tmp_name'];
                $mimeType     = $_FILES['spreadsheet']['type'];
                $sizeBytes    = (int) $_FILES['spreadsheet']['size'];

                $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
                if ($ext !== 'csv') {
                    $error = 'Only CSV files are supported.';
                } else {
                    // Primeiro verifica se já existe uma planilha com este nome para este usuário.
                    // Se existir, reutilizamos o registro existente sem contar um novo upload.
                    $dup = $pdo->prepare('SELECT id FROM spreadsheets WHERE user_id = :user_id AND original_name = :original_name LIMIT 1');
                    $dup->execute([
                        'user_id'       => $user['id'],
                        'original_name' => $originalName,
                    ]);
                    $existing = $dup->fetch();

                    if ($existing && isset($existing['id'])) {
                        $spreadsheetId = (int)$existing['id'];
                        $duplicateSpreadsheetMsg = 'This spreadsheet name already exists in your account. The existing file was reused and this upload was not counted or saved again.';
                    } else {
                        // Respeita o mesmo limite de upload de planilhas do plano atual para novos arquivos
                        [$canUpload, $planUploadError] = PlanHelper::canUploadSpreadsheet($pdo, (int)$user['id']);
                        if (!$canUpload) {
                            $error = $planUploadError;
                            $planUploadLocked = true;
                        } else {
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
                }
            }

            if (!$error) {
                // Verifica limite de gráficos do plano atual (considerando que esta requisição pode gerar vários gráficos)
                // Por simplicidade, assumimos ao menos 1 gráfico por requisição
                [$canGenerate, $planChartError] = PlanHelper::canGenerateCharts($pdo, (int)$user['id'], 1);
                if (!$canGenerate) {
                    $error = $planChartError;
                    $planChartsLocked = true;
                }
            }

            if (!$error) {
                if ($spreadsheetId <= 0) {
                    $error = 'Please select or upload a file.';
                } elseif ($prompt === '') {
                    $error = 'Please describe what you want to visualize.';
                } else {
                    // Tenta chamar API de IA, se houver API key configurada
                    $aiPayload = [
                        'status' => 'generated_stub',
                        'reason' => 'No AI call executed.',
                    ];

                    // Descobre caminho do arquivo selecionado
                    $sheetStmt = $pdo->prepare('SELECT stored_name, original_name, mime_type, size_bytes FROM spreadsheets WHERE id = :id AND user_id = :uid');
                    $sheetStmt->execute(['id' => $spreadsheetId, 'uid' => $user['id']]);
                    $sheet = $sheetStmt->fetch();

                    if ($sheet) {
                        $filePath = __DIR__ . '/../../storage/spreadsheets/' . $sheet['stored_name'];

                        // Para arquivos genéricos (PDF, DOCX, etc.), não assumimos estrutura tabular.
                        // Enviamos apenas metadados e, opcionalmente, um pequeno preview binário em base64
                        // para a IA ter contexto. A IA deve retornar os dados completos dos gráficos
                        // (labels e values) já prontos.

                        $filePreviewBase64 = null;
                        $structuredPreview = null;
                        $structuredPreviewChunks = null;

                        if (is_file($filePath)) {
                            // Preview binário curto para qualquer tipo de arquivo
                            $raw = @file_get_contents($filePath, false, null, 0, 8192);
                            if ($raw !== false) {
                                $filePreviewBase64 = base64_encode($raw);
                            }

                            // Quando possível, tentamos extrair uma visão estruturada dos dados
                            $ext = strtolower(pathinfo($sheet['original_name'], PATHINFO_EXTENSION));

                            if ($ext === 'csv') {
                                // Lê o CSV inteiro dividindo em chunks para múltiplas requisições à IA
                                if (($handle = fopen($filePath, 'r')) !== false) {
                                    $headers = fgetcsv($handle, 0, ',');
                                    if ($headers !== false) {
                                        $chunkSize = 1000;
                                        $structuredPreviewChunks = [];
                                        $rowsChunk = [];
                                        while (($row = fgetcsv($handle, 0, ',')) !== false) {
                                            $rowsChunk[] = $row;
                                            if (count($rowsChunk) >= $chunkSize) {
                                                $structuredPreviewChunks[] = [
                                                    'type'    => 'csv',
                                                    'headers' => $headers,
                                                    'rows'    => $rowsChunk,
                                                ];
                                                $rowsChunk = [];
                                            }
                                        }
                                        if (!empty($rowsChunk)) {
                                            $structuredPreviewChunks[] = [
                                                'type'    => 'csv',
                                                'headers' => $headers,
                                                'rows'    => $rowsChunk,
                                            ];
                                        }

                                        if (!empty($structuredPreviewChunks)) {
                                            $structuredPreview = $structuredPreviewChunks[0];
                                        }
                                    }
                                    fclose($handle);
                                }
                            } elseif ($ext === 'xml') {
                                // Extrai todos os nós filhos de primeiro nível de um XML simples dividindo em chunks
                                libxml_use_internal_errors(true);
                                $xml = simplexml_load_file($filePath);
                                if ($xml !== false) {
                                    $structuredPreviewChunks = [];
                                    $chunkSize = 500;
                                    $itemsChunk = [];
                                    foreach ($xml->children() as $child) {
                                        $item = [];
                                        // Atributos
                                        foreach ($child->attributes() as $k => $v) {
                                            $item[(string)$k] = (string)$v;
                                        }
                                        // Filhos de primeiro nível
                                        foreach ($child->children() as $ck => $cv) {
                                            $item[(string)$ck] = (string)$cv;
                                        }
                                        if (!empty($item)) {
                                            $itemsChunk[] = $item;
                                            if (count($itemsChunk) >= $chunkSize) {
                                                $structuredPreviewChunks[] = [
                                                    'type'  => 'xml',
                                                    'items' => $itemsChunk,
                                                ];
                                                $itemsChunk = [];
                                            }
                                        }
                                    }

                                    if (!empty($itemsChunk)) {
                                        $structuredPreviewChunks[] = [
                                            'type'  => 'xml',
                                            'items' => $itemsChunk,
                                        ];
                                    }

                                    if (!empty($structuredPreviewChunks)) {
                                        $structuredPreview = $structuredPreviewChunks[0];
                                    }
                                }
                                libxml_clear_errors();
                            } elseif (in_array($ext, ['xlsx', 'docx'], true)) {
                                // Arquivos Office: mantemos apenas metadados e preview binário.
                                // A IA deve inferir o conteúdo a partir disso.
                                $structuredPreview = [
                                    'type' => $ext,
                                    'note' => 'Office file; server did not parse full structure. Use your own parsing to infer labels from the file contents.',
                                ];
                            }
                        }

                        // Lê API key do usuário; se não houver, usa a chave global de um super_admin
                        $apiKey = null;
                        $apiStmt = $pdo->prepare("SELECT api_key FROM api_configs WHERE user_id = :uid AND provider = 'openai' LIMIT 1");
                        $apiStmt->execute(['uid' => $user['id']]);
                        $apiRow = $apiStmt->fetch();

                        if (!$apiRow || empty($apiRow['api_key'])) {
                            // Fallback: usa a primeira chave OpenAI cadastrada para um usuário super_admin
                            $apiStmt = $pdo->prepare(
                                "SELECT ac.api_key
                                 FROM api_configs ac
                                 INNER JOIN users u ON u.id = ac.user_id
                                 WHERE ac.provider = 'openai' AND u.role = 'super_admin'
                                 ORDER BY ac.id ASC
                                 LIMIT 1"
                            );
                            $apiStmt->execute();
                            $apiRow = $apiStmt->fetch();
                        }

                        if ($apiRow && !empty($apiRow['api_key'])) {
                            $apiKey = $apiRow['api_key'];

                            // Monta prompt para IA
                            // Agora a IA deve devolver um objeto JSON com um array "charts" (configurações de gráficos)
                            // e um array "insights" com análises textuais importantes sobre os dados.
                            // Os "insights" devem analisar toda a planilha, buscando padrões relevantes gerais
                            // (maiores custos, top categorias, itens com maior participação, tendências fortes etc.),
                            // mesmo que esses pontos não tenham sido mencionados explicitamente no user_request.
                            // Quando "structured_preview" estiver presente (ex.: CSV/XML), a IA
                            // deve usar os valores reais (por exemplo, nomes de produtos) como labels,
                            // evitando rótulos genéricos como "Product A", "Product B".
                            $systemPrompt = 'You are an assistant that designs ONE OR MORE chart configurations '
                                . 'based on a user request and the contents of an uploaded file. '
                                . 'You receive file metadata, an optional base64 preview, and sometimes a "structured_preview" '
                                . 'containing real headers and sample rows (for CSV/XML). '
                                . 'When structured_preview is present, ALWAYS use the real values from it as labels '
                                . '(for example, real product names or dates) instead of generic names like "Product A". '
                                . 'Return ONLY a JSON object with a top-level "charts" array and an "insights" array. '
                                . 'Each item in "charts" MUST have the fields: '
                                . 'chart_type (string, e.g. line, bar), '
                                . 'title (string), '
                                . 'description (string), '
                                . 'labels (array of x-axis labels as strings), '
                                . 'values (array of numeric values, same length as labels). '
                                . 'The top-level "insights" array should contain short textual analyses of the most relevant overall patterns '
                                . 'in the entire dataset (for example: categories with highest cost, biggest contributors, noticeable trends, anomalies), '
                                . 'not limited only to what the user_request explicitly asked. '
                                . 'Each item in "insights" must have: title (short string) and text (1-3 sentence explanation). '
                                . 'Write chart titles, descriptions and insights in the same language as the user_request (often Brazilian Portuguese).';

                            $chunksForIA = [];
                            if (is_array($structuredPreviewChunks) && !empty($structuredPreviewChunks)) {
                                $chunksForIA = $structuredPreviewChunks;
                            } else {
                                $chunksForIA = [$structuredPreview];
                            }

                            $totalChunks = count($chunksForIA);
                            $allCharts = [];
                            $allInsights = [];
                            $aiPayload = [
                                'status' => 'ok',
                                'charts' => [],
                            ];

                            foreach ($chunksForIA as $chunkIndex => $previewChunk) {
                                $userPrompt = [
                                    'user_request'       => $prompt,
                                    'file_name'          => $sheet['original_name'],
                                    'mime_type'          => $sheet['mime_type'],
                                    'size_bytes'         => (int)$sheet['size_bytes'],
                                    'file_preview_base64' => $filePreviewBase64,
                                    'structured_preview' => $previewChunk,
                                    'chunk_index'        => $chunkIndex + 1,
                                    'total_chunks'       => $totalChunks,
                                ];

                                $payload = [
                                    'model' => 'gpt-4o-mini',
                                    'messages' => [
                                        ['role' => 'system', 'content' => $systemPrompt],
                                        ['role' => 'user', 'content' => json_encode($userPrompt)],
                                    ],
                                    'response_format' => ['type' => 'json_object'],
                                ];

                                $ch = curl_init('https://api.openai.com/v1/chat/completions');
                                curl_setopt_array($ch, [
                                    CURLOPT_RETURNTRANSFER => true,
                                    CURLOPT_POST => true,
                                    CURLOPT_HTTPHEADER => [
                                        'Content-Type: application/json',
                                        'Authorization: Bearer ' . $apiKey,
                                    ],
                                    CURLOPT_POSTFIELDS => json_encode($payload),
                                ]);

                                $responseBody = curl_exec($ch);
                                $curlErr      = curl_error($ch);
                                $httpCode     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                                curl_close($ch);

                                if ($curlErr) {
                                    $aiPayload = [
                                        'status' => 'error',
                                        'error'  => 'Curl error: ' . $curlErr,
                                    ];
                                    $error = 'Failed to contact AI service (chunk ' . ($chunkIndex + 1) . ' of ' . $totalChunks . ').';
                                    break;
                                } elseif ($httpCode < 200 || $httpCode >= 300) {
                                    $aiPayload = [
                                        'status' => 'error',
                                        'error'  => 'HTTP ' . $httpCode,
                                        'body'   => $responseBody,
                                    ];
                                    $error = 'AI service returned an error (chunk ' . ($chunkIndex + 1) . ' of ' . $totalChunks . ').';
                                    break;
                                } else {
                                    $decoded = json_decode($responseBody, true);
                                    $content = $decoded['choices'][0]['message']['content'] ?? null;
                                    if ($content) {
                                        $parsed = json_decode($content, true);
                                        if (json_last_error() === JSON_ERROR_NONE) {
                                            $chartsList = [];
                                            if (isset($parsed['charts']) && is_array($parsed['charts'])) {
                                                $chartsList = $parsed['charts'];
                                            } elseif (is_array($parsed) && isset($parsed['chart_type'])) {
                                                // backward compatibility: single chart object
                                                $chartsList = [$parsed];
                                            }

                                            foreach ($chartsList as $chartConfig) {
                                                $allCharts[] = $chartConfig;
                                            }

                                            // Agrega insights textuais deste chunk, se houver
                                            if (isset($parsed['insights']) && is_array($parsed['insights'])) {
                                                foreach ($parsed['insights'] as $insight) {
                                                    if (is_array($insight)) {
                                                        $text = trim((string)($insight['text'] ?? ''));
                                                        $title = trim((string)($insight['title'] ?? ''));
                                                        if ($text !== '') {
                                                            $allInsights[] = [
                                                                'title' => $title,
                                                                'text'  => $text,
                                                            ];
                                                        }
                                                    } else {
                                                        $text = trim((string)$insight);
                                                        if ($text !== '') {
                                                            $allInsights[] = [
                                                                'title' => '',
                                                                'text'  => $text,
                                                            ];
                                                        }
                                                    }
                                                }
                                            }
                                        } else {
                                            $aiPayload = [
                                                'status' => 'error',
                                                'error'  => 'Invalid JSON from AI.',
                                                'raw'    => $content,
                                            ];
                                            $error = 'AI response could not be parsed (chunk ' . ($chunkIndex + 1) . ' of ' . $totalChunks . ').';
                                            break;
                                        }
                                    } else {
                                        $aiPayload = [
                                            'status' => 'error',
                                            'error'  => 'No content from AI.',
                                            'raw'    => $decoded,
                                        ];
                                        $error = 'AI did not return content (chunk ' . ($chunkIndex + 1) . ' of ' . $totalChunks . ').';
                                        break;
                                    }
                                }
                            }

                            if (!$error && $aiPayload['status'] === 'ok') {
                                $aiPayload['charts'] = $allCharts;
                                $aiPayload['insights'] = $allInsights;
                                $aiPayload['chunks_used'] = $totalChunks;
                            }
                        } else {
                            // sem API key, mantemos stub
                            $aiPayload = [
                                'status' => 'generated_stub',
                                'reason' => 'No API key configured. Set it in Settings > API Configuration.',
                            ];
                        }
                    }

                    // Salva registro(s) de gráfico com payload (stub ou IA real)
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
                                $chartsData[] = [
                                    'type'   => $chartConfig['chart_type'] ?? 'line',
                                    'title'  => $chartConfig['title'] ?? 'Generated chart',
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

                    // Prepara insights textuais para a view, se existirem na resposta da IA
                    if (!empty($aiPayload['insights']) && is_array($aiPayload['insights'])) {
                        foreach ($aiPayload['insights'] as $insight) {
                            if (!is_array($insight)) {
                                $text = trim((string)$insight);
                                if ($text !== '') {
                                    $insightsData[] = [
                                        'title' => '',
                                        'text'  => $text,
                                    ];
                                }
                                continue;
                            }

                            $text = trim((string)($insight['text'] ?? ''));
                            $title = trim((string)($insight['title'] ?? ''));
                            if ($text !== '') {
                                $insightsData[] = [
                                    'title' => $title,
                                    'text'  => $text,
                                ];
                            }
                        }
                    }

                    $lastChartResponse = $aiPayload;

                    if ($aiPayload['status'] === 'ok' && !$error) {
                        $success = 'Charts generated successfully with AI.';
                    } elseif (!$error) {
                        $success = 'Chart generated (stub). Configure API key in Settings for AI-powered charts.';
                    }

                    if (!$error && $duplicateSpreadsheetMsg !== '') {
                        $success .= ' ' . $duplicateSpreadsheetMsg;
                    }
                }
            }
        }

        // Métricas simples para os cards (apenas do usuário logado)
        $stmt = $pdo->prepare('SELECT COUNT(*) AS c FROM spreadsheets WHERE user_id = :uid');
        $stmt->execute(['uid' => $user['id']]);
        $totalSpreadsheets = (int)($stmt->fetch()['c'] ?? 0);

        $stmt = $pdo->prepare('SELECT COUNT(*) AS c FROM charts WHERE user_id = :uid');
        $stmt->execute(['uid' => $user['id']]);
        $totalCharts = (int)($stmt->fetch()['c'] ?? 0);

        // Por enquanto, consideramos cada chart gerado pelo usuário como um "Saved Dashboard"
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
