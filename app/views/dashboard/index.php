<?php require_once __DIR__ . '/../../config/config.php';

// ============================================================
// PREPARAÇÃO ROBUSTA DE DADOS PARA A VIEW
// Usa $analysisResult (pipeline IA) OU $lastChartResponse (fallback)
// ============================================================

$hasAIResult = !empty($analysisResult) && is_array($analysisResult) && empty($analysisResult['needs_clarification']);
$hasCharts = !empty($chartsData);
$hasAnalysis = !empty($analysisReportText);

// Extrair request_plan de múltiplas fontes
$rp = [];
if ($hasAIResult && !empty($analysisResult['request_plan'])) {
    $rp = $analysisResult['request_plan'];
} elseif (!empty($lastChartResponse['request_plan']) && is_array($lastChartResponse['request_plan'])) {
    $rp = $lastChartResponse['request_plan'];
}

// Extrair understanding
$aiUnderstanding = [];
if (is_array($rp) && isset($rp['ai_plan']['understanding'])) {
    $aiUnderstanding = $rp['ai_plan']['understanding'];
}

// Extrair stages
$aiStages = [];
if ($hasAIResult && !empty($analysisResult['stages'])) {
    $aiStages = $analysisResult['stages'];
} elseif (!empty($lastChartResponse['stages']) && is_array($lastChartResponse['stages'])) {
    $aiStages = $lastChartResponse['stages'];
}

// Extrair analytics results e kpis
$segResults = [];
$analysisKpis = [];
if ($hasAIResult) {
    $segResults = $analysisResult['analytics']['results'] ?? [];
    $analysisKpis = $analysisResult['analytics']['kpis'] ?? [];
}

// Determinar se temos resultados para mostrar
$hasResults = $hasAIResult || $hasAnalysis || $hasCharts || !empty($rp);

// Calcular KPIs dinâmicos a partir dos dados reais
$dynKpis = [];
if (!empty($dataRows) && !empty($dataHeaders)) {
    // Tentar encontrar coluna métrica do request_plan OU auto-detectar primeira coluna numérica
    $metricColName = $rp['metric_column'] ?? null;
    $metricIdx = $metricColName ? array_search($metricColName, $dataHeaders, true) : false;

    // Auto-detectar coluna numérica se não encontrou
    if ($metricIdx === false) {
        foreach ($dataHeaders as $autoIdx => $autoH) {
            $numCount = 0;
            foreach (array_slice($dataRows, 0, 20) as $sRow) {
                $sv = trim((string)($sRow[$autoIdx] ?? ''));
                $sv = str_replace(['R$', ' ', '.'], '', $sv);
                $sv = str_replace(',', '.', $sv);
                if ($sv !== '' && is_numeric($sv)) $numCount++;
            }
            if ($numCount >= 10) {
                $metricIdx = $autoIdx;
                $metricColName = $autoH;
                break;
            }
        }
    }

    $totalSum = 0; $totalCount = 0; $minVal = PHP_FLOAT_MAX; $maxVal = PHP_FLOAT_MIN;
    if ($metricIdx !== false) {
        foreach ($dataRows as $row) {
            $raw = trim((string)($row[$metricIdx] ?? ''));
            if ($raw === '') continue;
            $v = str_replace(['R$', ' ', '.'], '', $raw);
            $v = str_replace(',', '.', $v);
            $v = (float)$v;
            if ($v == 0 && strpos($raw, '0') === false) continue;
            $totalSum += $v;
            $totalCount++;
            if ($v < $minVal) $minVal = $v;
            if ($v > $maxVal) $maxVal = $v;
        }
    }
    if ($totalCount > 0) {
        $dynKpis = [
            'total_sum' => $totalSum,
            'total_count' => $totalCount,
            'average' => round($totalSum / $totalCount, 2),
            'min' => $minVal,
            'max' => $maxVal,
            'metric_column' => $metricColName,
            'total_rows' => count($dataRows),
        ];
    }
}

// Extrair valores únicos de colunas categóricas para filtros
$filterOptions = [];
if (!empty($dataHeaders) && !empty($dataRows)) {
    foreach ($dataHeaders as $ci => $ch) {
        $seen = [];
        $isNum = true;
        $sampleCount = 0;
        foreach (array_slice($dataRows, 0, 50) as $row) {
            $v = trim((string)($row[$ci] ?? ''));
            if ($v !== '') {
                $sampleCount++;
                if (!is_numeric(str_replace([',', '.', 'R$', ' ', '-'], '', $v))) {
                    $isNum = false;
                }
            }
        }
        if ($isNum && $sampleCount > 0) continue;
        foreach ($dataRows as $row) {
            $v = trim((string)($row[$ci] ?? ''));
            if ($v !== '' && !isset($seen[$v]) && count($seen) < 60) {
                $seen[$v] = true;
            }
        }
        if (count($seen) >= 2 && count($seen) <= 60) {
            $filterOptions[$ch] = array_keys($seen);
            sort($filterOptions[$ch]);
        }
    }
}

// Construir segmentação completa: de $segResults OU de $chartsData OU de $dataRows
$segData = [];
if (!empty($segResults) && is_array($segResults)) {
    // Usar resultados do analytics diretamente (mais completo)
    $segTotal = array_sum($segResults);
    $segMax = !empty($segResults) ? max(array_map('abs', $segResults)) : 1;
    foreach ($segResults as $label => $value) {
        if ($label === '__total__') continue;
        $segData[] = [
            'label' => (string)$label,
            'value' => (float)$value,
            'pct' => $segTotal != 0 ? round(((float)$value / $segTotal) * 100, 1) : 0,
            'bar_pct' => $segMax != 0 ? round((abs((float)$value) / $segMax) * 100, 1) : 0,
        ];
    }
} elseif ($hasCharts) {
    // Fallback: extrair do primeiro gráfico de barras
    foreach ($chartsData as $ch) {
        $chType = $ch['type'] ?? '';
        if (($chType === 'bar' || $chType === 'pie') && !empty($ch['labels']) && !empty($ch['values']) && count($ch['labels']) > 1) {
            $segTotal = array_sum($ch['values']);
            $segMax = max(array_map('abs', $ch['values']));
            for ($si = 0; $si < count($ch['labels']); $si++) {
                $segData[] = [
                    'label' => (string)$ch['labels'][$si],
                    'value' => (float)$ch['values'][$si],
                    'pct' => $segTotal != 0 ? round(($ch['values'][$si] / $segTotal) * 100, 1) : 0,
                    'bar_pct' => $segMax != 0 ? round((abs($ch['values'][$si]) / $segMax) * 100, 1) : 0,
                ];
            }
            break;
        }
    }
}

// Ordenar segmentação por valor decrescente
usort($segData, function($a, $b) { return abs($b['value']) <=> abs($a['value']); });

$fmtBRL = function($v) {
    return 'R$ ' . number_format((float)$v, 2, ',', '.');
};
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>EasyChart - Dashboard Analítico</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        *{box-sizing:border-box;}
        body{margin:0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;background:#f0f2f5;color:#111827;}
        .layout{min-height:100vh;display:flex;flex-direction:column;}
        .topbar{height:52px;background:#0f172a;color:#e5e7eb;display:flex;align-items:center;justify-content:space-between;padding:0 24px;position:sticky;top:0;z-index:100;}
        .topbar-left{display:flex;align-items:center;gap:16px;}
        .logo-mark{display:flex;align-items:center;gap:6px;color:#e5e7eb;font-weight:600;font-size:15px;}
        .logo-icon{width:24px;height:24px;border-radius:7px;background:linear-gradient(135deg,#2563eb,#4ade80);display:flex;align-items:center;justify-content:center;}
        .logo-icon-bar{width:4px;border-radius:3px;background:#ffffff;margin:0 1px;}
        .top-nav a{color:#94a3b8;font-size:13px;margin-right:16px;text-decoration:none;transition:color .15s;}
        .top-nav a:hover,.top-nav a.active{color:#ffffff;font-weight:600;}
        .lang-switcher{margin-left:16px;display:flex;align-items:center;gap:4px;font-size:12px;}
        .lang-switcher a{color:#94a3b8;text-decoration:none;padding:2px 6px;border-radius:4px;}
        .lang-switcher a.active{color:#ffffff;font-weight:600;background:rgba(255,255,255,0.1);}
        .topbar-right{font-size:13px;display:flex;align-items:center;gap:14px;}
        .topbar-right a{color:#e5e7eb;text-decoration:none;}

        .content{flex:1;padding:20px 24px 40px;max-width:100%;width:100%;}
        .page-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;}
        .page-title{font-size:22px;font-weight:700;color:#0f172a;}
        .page-subtitle{font-size:13px;color:#64748b;}

        .kpi-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:20px;}
        .kpi-card{background:#fff;border-radius:12px;padding:14px 16px;display:flex;justify-content:space-between;align-items:center;border:1px solid #e2e8f0;transition:box-shadow .2s;}
        .kpi-card:hover{box-shadow:0 4px 12px rgba(0,0,0,0.06);}
        .kpi-label{font-size:11px;text-transform:uppercase;letter-spacing:0.5px;color:#94a3b8;margin-bottom:4px;font-weight:600;}
        .kpi-value{font-size:22px;font-weight:700;color:#0f172a;}
        .kpi-icon{width:36px;height:36px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:18px;}
        .kpi-icon.blue{background:#eff6ff;color:#2563eb;}
        .kpi-icon.green{background:#f0fdf4;color:#16a34a;}
        .kpi-icon.purple{background:#faf5ff;color:#7c3aed;}
        .kpi-icon.orange{background:#fff7ed;color:#ea580c;}

        .card{background:#fff;border-radius:12px;border:1px solid #e2e8f0;margin-bottom:16px;overflow:hidden;}
        .card-head{padding:16px 20px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between;}
        .card-head-title{font-size:15px;font-weight:700;color:#0f172a;}
        .card-head-sub{font-size:12px;color:#64748b;margin-top:2px;}
        .card-body{padding:20px;}
        .card-body-flush{padding:0;}

        .form-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
        .form-full{grid-column:1/-1;}
        .field-label{font-size:12px;font-weight:600;color:#374151;margin-bottom:5px;text-transform:uppercase;letter-spacing:0.3px;}
        .input,.select{width:100%;border-radius:8px;border:1px solid #d1d5db;padding:9px 12px;font-size:14px;outline:none;transition:border-color .15s;}
        .input:focus,.select:focus{border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,0.1);}
        .select{background:#fff;}
        .btn{padding:10px 20px;border-radius:8px;border:none;font-size:14px;font-weight:600;cursor:pointer;transition:all .15s;}
        .btn-primary{background:#2563eb;color:#fff;}
        .btn-primary:hover{background:#1d4ed8;box-shadow:0 2px 8px rgba(37,99,235,0.3);}
        .btn-secondary{background:#64748b;color:#fff;}
        .btn-secondary:hover{background:#475569;}
        .btn-group{display:flex;gap:8px;margin-top:16px;}

        .alert{border-radius:8px;padding:10px 14px;font-size:13px;margin-bottom:12px;display:flex;align-items:center;gap:8px;}
        .alert-error{background:#fef2f2;border:1px solid #fecaca;color:#991b1b;}
        .alert-success{background:#f0fdf4;border:1px solid #bbf7d0;color:#166534;}

        .analysis-container{display:grid;grid-template-columns:1fr;gap:16px;}
        .analysis-text{line-height:1.7;font-size:14px;color:#1e293b;}
        .analysis-text h3{font-size:16px;font-weight:700;color:#0f172a;margin:20px 0 8px;padding-bottom:6px;border-bottom:2px solid #e2e8f0;}
        .analysis-text h3:first-child{margin-top:0;}
        .analysis-text p{margin:6px 0 12px;line-height:1.7;}
        .analysis-text ul,.analysis-text ol{margin:6px 0 12px;padding-left:20px;}
        .analysis-text li{margin-bottom:4px;line-height:1.6;}
        .analysis-text strong{color:#0f172a;}

        .charts-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(420px,1fr));gap:16px;margin-top:0;}
        .chart-card{background:#fff;border-radius:12px;border:1px solid #e2e8f0;overflow:hidden;}
        .chart-card-head{padding:14px 18px;border-bottom:1px solid #f1f5f9;}
        .chart-card-title{font-size:14px;font-weight:700;color:#0f172a;}
        .chart-card-sub{font-size:12px;color:#64748b;margin-top:2px;}
        .chart-card-caveat{font-size:11px;color:#92400e;margin-top:4px;background:#fffbeb;padding:4px 8px;border-radius:4px;display:inline-block;}
        .chart-card-body{padding:16px;position:relative;height:320px;}

        .detail-table{width:100%;border-collapse:collapse;font-size:13px;}
        .detail-table thead{background:#f8fafc;position:sticky;top:0;}
        .detail-table th{padding:10px 14px;text-align:left;font-weight:600;color:#475569;border-bottom:2px solid #e2e8f0;font-size:12px;text-transform:uppercase;letter-spacing:0.3px;}
        .detail-table td{padding:8px 14px;border-bottom:1px solid #f1f5f9;color:#334155;}
        .detail-table tbody tr:hover{background:#f8fafc;}
        .detail-table .num{text-align:right;font-variant-numeric:tabular-nums;font-weight:600;}
        .detail-table .rank{color:#94a3b8;font-size:12px;width:40px;}
        .detail-table .bar-cell{width:120px;}
        .detail-bar{height:8px;border-radius:4px;background:#2563eb;transition:width .3s;}

        .tech-summary{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;}
        .tech-item{background:#f8fafc;border-radius:8px;padding:12px 14px;border:1px solid #e2e8f0;}
        .tech-item-label{font-size:11px;text-transform:uppercase;letter-spacing:0.5px;color:#94a3b8;font-weight:600;margin-bottom:4px;}
        .tech-item-value{font-size:15px;font-weight:700;color:#0f172a;}
        .tech-item-sub{font-size:12px;color:#64748b;margin-top:2px;}

        .section-title{font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;color:#64748b;margin:24px 0 12px;display:flex;align-items:center;gap:8px;}
        .section-title::after{content:'';flex:1;height:1px;background:#e2e8f0;}

        .empty-state{text-align:center;color:#94a3b8;font-size:14px;padding:40px 20px;}

        details summary{cursor:pointer;font-size:13px;color:#2563eb;font-weight:500;padding:8px 0;}
        details summary:hover{color:#1d4ed8;}
        details pre{white-space:pre-wrap;font-size:11px;background:#0f172a;color:#e2e8f0;border-radius:8px;padding:12px;max-height:400px;overflow:auto;}

        @media(max-width:768px){
            .kpi-grid{grid-template-columns:repeat(2,1fr);}
            .form-grid{grid-template-columns:1fr;}
            .charts-grid{grid-template-columns:1fr;}
            .tech-summary{grid-template-columns:1fr;}
        }
    </style>
</head>
<body>
<div class="layout">
    <header class="topbar">
        <div class="topbar-left">
            <div class="logo-mark">
                <div class="logo-icon">
                    <div class="logo-icon-bar" style="height:10px;"></div>
                    <div class="logo-icon-bar" style="height:16px;opacity:.85;"></div>
                    <div class="logo-icon-bar" style="height:12px;opacity:.7;"></div>
                </div>
                <span>EasyChart</span>
            </div>
            <nav class="top-nav">
                <a href="<?= BASE_URL ?>?c=dashboard&a=index" class="active"><?= Lang::get('Dashboard') ?></a>
                <a href="<?= BASE_URL ?>?c=spreadsheets&a=index"><?= Lang::get('Spreadsheets') ?></a>
                <a href="<?= BASE_URL ?>?c=reports&a=index">Relatórios</a>
                <a href="<?= BASE_URL ?>?c=settings&a=index"><?= Lang::get('Settings') ?></a>
                <?php if (isset($_SESSION['user']['role']) && $_SESSION['user']['role'] === 'super_admin'): ?>
                <a href="<?= BASE_URL ?>?c=admin&a=index"><?= Lang::get('AI Admin') ?></a>
                <?php endif; ?>
                <div class="lang-switcher">
                    <a href="<?= BASE_URL ?>?c=lang&a=switch&lang=pt" class="<?= Lang::getCurrentLang() === 'pt' ? 'active' : '' ?>">PT</a>
                    <span>|</span>
                    <a href="<?= BASE_URL ?>?c=lang&a=switch&lang=en" class="<?= Lang::getCurrentLang() === 'en' ? 'active' : '' ?>">EN</a>
                </div>
            </nav>
        </div>
        <div class="topbar-right">
            <span><?= Lang::get('Welcome') ?>, <?= isset($_SESSION['user']['full_name']) ? htmlspecialchars($_SESSION['user']['full_name']) : 'User' ?></span>
            <a href="<?= BASE_URL ?>?c=dashboard&a=logout"><?= Lang::get('Logout') ?></a>
        </div>
    </header>

    <main class="content">
        <div class="page-header">
            <div>
                <div class="page-title"><?= Lang::get('Dashboard') ?></div>
                <div class="page-subtitle"><?= Lang::get('Generate charts from your spreadsheets using AI') ?></div>
            </div>
        </div>

        <section class="kpi-grid">
            <div class="kpi-card">
                <div>
                    <div class="kpi-label"><?= Lang::get('Total Spreadsheets') ?></div>
                    <div class="kpi-value"><?= isset($totalSpreadsheets) ? (int)$totalSpreadsheets : 0 ?></div>
                </div>
                <div class="kpi-icon blue">📄</div>
            </div>
            <div class="kpi-card">
                <div>
                    <div class="kpi-label"><?= Lang::get('Generated Charts') ?></div>
                    <div class="kpi-value"><?= isset($totalCharts) ? (int)$totalCharts : 0 ?></div>
                </div>
                <div class="kpi-icon green">📊</div>
            </div>
            <div class="kpi-card">
                <div>
                    <div class="kpi-label"><?= Lang::get('Saved Dashboards') ?></div>
                    <div class="kpi-value"><?= isset($savedDashboards) ? (int)$savedDashboards : 0 ?></div>
                </div>
                <div class="kpi-icon purple">📈</div>
            </div>
            <div class="kpi-card">
                <div>
                    <div class="kpi-label"><?= Lang::get('AI Insights') ?></div>
                    <div class="kpi-value"><?= isset($aiInsights) ? (int)$aiInsights : 0 ?></div>
                </div>
                <div class="kpi-icon orange">⚡</div>
            </div>
        </section>

        <?php if (!empty($error)): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <?php if (!empty($success)): ?>
            <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        <!-- ===== FORMULÁRIO DE GERAÇÃO ===== -->
        <div class="card" style="margin-bottom:20px;">
            <div class="card-head">
                <div>
                    <div class="card-head-title"><?= Lang::get('AI Chart Generator') ?></div>
                    <div class="card-head-sub"><?= Lang::get('Tell me what you want to visualize and I\'ll create the perfect chart for you') ?></div>
                </div>
            </div>
            <div class="card-body">
                <form method="post" enctype="multipart/form-data">
                    <div class="form-grid">
                        <div>
                            <div class="field-label"><?= Lang::get('Select Spreadsheet') ?></div>
                            <select class="select" name="spreadsheet_id">
                                <option value=""><?= Lang::get('Choose a spreadsheet...') ?></option>
                                <?php if (!empty($spreadsheets)): ?>
                                    <?php foreach ($spreadsheets as $sheet): ?>
                                        <option value="<?= (int)$sheet['id'] ?>" <?= (!empty($spreadsheetId) && (int)$spreadsheetId === (int)$sheet['id']) ? 'selected' : '' ?>><?= htmlspecialchars($sheet['original_name']) ?></option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                        </div>
                        <div>
                            <div class="field-label"><?= Lang::get('Or upload a new spreadsheet') ?></div>
                            <input type="file" name="spreadsheet" class="input">
                        </div>
                        <div class="form-full">
                            <div class="field-label"><?= Lang::get('What do you want to visualize?') ?></div>
                            <input class="input" name="prompt" value="<?= htmlspecialchars($prompt ?? '') ?>" placeholder="Ex: quanto gastei com materiais? / mostre a evolução mensal de despesas">
                        </div>
                    </div>

                    <?php if (!empty($clarificationQuestions) && is_array($clarificationQuestions)): ?>
                        <div style="margin-top:14px;border:1px solid #e2e8f0;border-radius:10px;padding:14px;background:#f8fafc;">
                            <div style="font-weight:700;margin-bottom:6px;font-size:14px;">Desambiguação necessária</div>
                            <div style="font-size:12px;color:#64748b;margin-bottom:10px;">Responda para aumentar a precisão ou pule para modo conservador.</div>
                            <?php foreach ($clarificationQuestions as $q): ?>
                                <?php
                                    $qid = (string)($q['id'] ?? '');
                                    $qlabel = (string)($q['label'] ?? '');
                                    $qwhy = (string)($q['why'] ?? '');
                                    $qopts = $q['options'] ?? [];
                                    $qdef = $q['default'] ?? null;
                                ?>
                                <?php if ($qid !== '' && $qlabel !== '' && is_array($qopts) && !empty($qopts)): ?>
                                    <div style="margin-bottom:10px;">
                                        <div class="field-label"><?= htmlspecialchars($qlabel) ?></div>
                                        <?php if ($qwhy !== ''): ?>
                                            <div style="font-size:11px;color:#64748b;margin-bottom:4px;"><?= htmlspecialchars($qwhy) ?></div>
                                        <?php endif; ?>
                                        <select class="select" name="overrides[<?= htmlspecialchars($qid) ?>]">
                                            <option value="">Selecione...</option>
                                            <?php foreach ($qopts as $opt): ?>
                                                <?php $optStr = (string)$opt; ?>
                                                <option value="<?= htmlspecialchars($optStr) ?>" <?= ($qdef !== null && (string)$qdef === $optStr) ? 'selected' : '' ?>><?= htmlspecialchars($optStr) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            <div class="btn-group">
                                <button class="btn btn-primary" type="submit">Reprocessar</button>
                                <button class="btn btn-secondary" type="submit" name="skip_clarification" value="1">Pular (conservador)</button>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="btn-group">
                            <button class="btn btn-primary" type="submit"><?= Lang::get('Generate') ?></button>
                        </div>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <?php if ($hasResults): ?>

        <!-- ===== KPI CARDS DINÂMICOS ===== -->
        <?php if (!empty($dynKpis)): ?>
        <div class="section-title">Indicadores da Análise</div>
        <div class="kpi-grid" style="grid-template-columns:repeat(5,1fr);">
            <div class="kpi-card">
                <div>
                    <div class="kpi-label">Total (<?= htmlspecialchars((string)($dynKpis['metric_column'] ?? 'Valor')) ?>)</div>
                    <div class="kpi-value" style="font-size:18px;"><?= $fmtBRL($dynKpis['total_sum']) ?></div>
                </div>
                <div class="kpi-icon blue" style="font-size:20px;">$</div>
            </div>
            <div class="kpi-card">
                <div>
                    <div class="kpi-label">Registros</div>
                    <div class="kpi-value"><?= number_format($dynKpis['total_count'], 0, ',', '.') ?></div>
                    <div style="font-size:11px;color:#64748b;">de <?= number_format($dynKpis['total_rows'], 0, ',', '.') ?> linhas</div>
                </div>
                <div class="kpi-icon green" style="font-size:20px;">#</div>
            </div>
            <div class="kpi-card">
                <div>
                    <div class="kpi-label">Ticket Médio</div>
                    <div class="kpi-value" style="font-size:18px;"><?= $fmtBRL($dynKpis['average']) ?></div>
                </div>
                <div class="kpi-icon purple" style="font-size:20px;">x&#772;</div>
            </div>
            <div class="kpi-card">
                <div>
                    <div class="kpi-label">Maior Valor</div>
                    <div class="kpi-value" style="font-size:18px;"><?= $fmtBRL($dynKpis['max']) ?></div>
                </div>
                <div class="kpi-icon orange" style="font-size:20px;">&uarr;</div>
            </div>
            <div class="kpi-card">
                <div>
                    <div class="kpi-label">Menor Valor</div>
                    <div class="kpi-value" style="font-size:18px;"><?= $fmtBRL($dynKpis['min']) ?></div>
                </div>
                <div class="kpi-icon" style="background:#fef2f2;color:#dc2626;font-size:20px;">&darr;</div>
            </div>
        </div>
        <?php endif; ?>

        <!-- ===== RESUMO TÉCNICO ===== -->
        <?php if (!empty($rp)): ?>
        <div class="section-title">Resumo Técnico da Análise</div>
        <div class="card">
            <div class="card-body">
                <div class="tech-summary">
                    <?php if (!empty($rp['interpretation'])): ?>
                    <div class="tech-item" style="grid-column:1/-1;">
                        <div class="tech-item-label">Interpretação da IA</div>
                        <div class="tech-item-value" style="font-size:14px;font-weight:600;"><?= htmlspecialchars((string)$rp['interpretation']) ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($rp['metric_op'])): ?>
                    <div class="tech-item">
                        <div class="tech-item-label">Operação</div>
                        <div class="tech-item-value"><?= htmlspecialchars(strtoupper((string)$rp['metric_op'])) ?></div>
                        <?php if (!empty($rp['metric_column'])): ?>
                        <div class="tech-item-sub">Coluna: <?= htmlspecialchars((string)$rp['metric_column']) ?></div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($rp['group_by'])): ?>
                    <div class="tech-item">
                        <div class="tech-item-label">Agrupamento</div>
                        <div class="tech-item-value"><?= htmlspecialchars((string)$rp['group_by']) ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($rp['time_axis'])): ?>
                    <div class="tech-item">
                        <div class="tech-item-label">Eixo Temporal</div>
                        <div class="tech-item-value"><?= htmlspecialchars((string)$rp['time_axis']) ?></div>
                    </div>
                    <?php endif; ?>
                    <?php
                    $fList = $rp['applied_filters'] ?? $rp['filters'] ?? [];
                    if (!empty($fList) && is_array($fList)):
                    ?>
                    <div class="tech-item">
                        <div class="tech-item-label">Filtros Aplicados</div>
                        <div class="tech-item-value" style="font-size:12px;font-weight:500;">
                            <?php foreach ($fList as $f): ?>
                                <?php if (is_array($f)): ?>
                                    <div><?= htmlspecialchars(($f['column'] ?? '') . ' ' . ($f['op'] ?? '') . ' "' . ($f['value'] ?? '') . '"') ?></div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($aiUnderstanding['spreadsheet_description'])): ?>
                    <div class="tech-item">
                        <div class="tech-item-label">Tipo de Planilha</div>
                        <div class="tech-item-value" style="font-size:13px;font-weight:500;"><?= htmlspecialchars((string)$aiUnderstanding['spreadsheet_description']) ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($analysisKpis)): ?>
                        <?php if (isset($analysisKpis['total_rows_filtered'])): ?>
                        <div class="tech-item">
                            <div class="tech-item-label">Registros na Análise IA</div>
                            <div class="tech-item-value"><?= number_format((int)$analysisKpis['total_rows_filtered'], 0, ',', '.') ?></div>
                            <div class="tech-item-sub">de <?= number_format((int)($analysisKpis['total_rows_original'] ?? 0), 0, ',', '.') ?> originais</div>
                        </div>
                        <?php endif; ?>
                        <div class="tech-item">
                            <div class="tech-item-label">Total na Planilha</div>
                            <div class="tech-item-value"><?= number_format(count($dataRows ?? []), 0, ',', '.') ?></div>
                            <div class="tech-item-sub">registros completos disponíveis abaixo</div>
                        </div>
                        <?php if (isset($analysisKpis['unique_groups']) && (int)$analysisKpis['unique_groups'] > 0): ?>
                        <div class="tech-item">
                            <div class="tech-item-label">Grupos Únicos</div>
                            <div class="tech-item-value"><?= (int)$analysisKpis['unique_groups'] ?></div>
                        </div>
                        <?php endif; ?>
                    <?php endif; ?>
                    <?php
                    // Aviso quando a IA filtrou um subconjunto
                    $filteredCount = (int)($analysisKpis['total_rows_filtered'] ?? 0);
                    $originalCount = (int)($analysisKpis['total_rows_original'] ?? 0);
                    if ($filteredCount > 0 && $originalCount > 0 && $filteredCount < $originalCount):
                    ?>
                    <div class="tech-item" style="grid-column:1/-1;background:#fef3c7;border-color:#fbbf24;">
                        <div class="tech-item-label" style="color:#92400e;">Aviso: Análise Parcial</div>
                        <div class="tech-item-value" style="font-size:13px;font-weight:500;color:#78350f;">
                            A IA analisou <?= number_format($filteredCount, 0, ',', '.') ?> de <?= number_format($originalCount, 0, ',', '.') ?> registros
                            porque aplicou filtros baseados na sua pergunta.
                            Os <strong><?= number_format(count($dataRows ?? []), 0, ',', '.') ?> registros completos</strong> da planilha estão disponíveis
                            na seção "Explorador de Dados" abaixo.
                            Para analisar todos os dados, faça uma pergunta mais abrangente (ex: "analise todos os dados da planilha").
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <?php if (!empty($aiStages) && is_array($aiStages)): ?>
                <div style="margin-top:14px;padding-top:14px;border-top:1px solid #e2e8f0;">
                    <div style="font-size:11px;font-weight:600;text-transform:uppercase;color:#94a3b8;margin-bottom:8px;">Pipeline de Processamento IA</div>
                    <div style="display:grid;grid-template-columns:repeat(<?= count($aiStages) ?>,1fr);gap:6px;">
                        <?php $stepNum = 1; foreach ($aiStages as $sk => $sv): ?>
                            <?php if (is_array($sv)): ?>
                            <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:6px;padding:8px 6px;text-align:center;">
                                <div style="font-size:18px;font-weight:700;color:#16a34a;"><?= $stepNum ?></div>
                                <div style="font-size:10px;color:#166534;margin-top:2px;line-height:1.3;"><?= htmlspecialchars((string)($sv['title'] ?? '')) ?></div>
                            </div>
                            <?php $stepNum++; endif; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (!empty($decisionLog) && is_array($decisionLog)): ?>
                <details style="margin-top:8px;">
                    <summary>Log de decisão completo (JSON)</summary>
                    <pre><?= htmlspecialchars(json_encode($decisionLog, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
                </details>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- ===== ANÁLISE TEXTUAL ===== -->
        <?php if ($hasAnalysis): ?>
        <div class="section-title">Análise Inteligente por IA</div>
        <div class="card">
            <div class="card-head">
                <div>
                    <div class="card-head-title">Análise Profunda &mdash; 5 Níveis</div>
                    <div class="card-head-sub">
                        <?php if (!empty($rp['interpretation'])): ?>
                            <?= htmlspecialchars((string)$rp['interpretation']) ?>
                        <?php else: ?>
                            Análise gerada com base nos seus dados e na sua pergunta.
                        <?php endif; ?>
                    </div>
                </div>
                <?php if (!empty($analysisReportId)): ?>
                    <a href="<?= BASE_URL ?>?c=reports&a=view&id=<?= (int)$analysisReportId ?>" style="font-size:13px;color:#2563eb;text-decoration:none;font-weight:600;">Ver relatório &rarr;</a>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <div class="analysis-text"><?= $analysisReportText ?></div>
            </div>
        </div>
        <?php endif; ?>

        <!-- ===== GRÁFICOS ===== -->
        <?php if ($hasCharts): ?>
        <div class="section-title">Visualizações (<?= count($chartsData) ?> gráficos)</div>
        <div class="charts-grid">
            <?php foreach ($chartsData as $idx => $chart): ?>
            <div class="chart-card">
                <div class="chart-card-head">
                    <div class="chart-card-title"><?= htmlspecialchars($chart['title'] ?? 'Gráfico') ?></div>
                    <?php if (!empty($chart['insight'])): ?>
                        <div class="chart-card-sub"><?= htmlspecialchars((string)$chart['insight']) ?></div>
                    <?php elseif (!empty($chart['description'])): ?>
                        <div class="chart-card-sub"><?= htmlspecialchars((string)$chart['description']) ?></div>
                    <?php endif; ?>
                    <?php if (!empty($chart['caveat'])): ?>
                        <div class="chart-card-caveat"><?= htmlspecialchars((string)$chart['caveat']) ?></div>
                    <?php endif; ?>
                </div>
                <div class="chart-card-body">
                    <canvas id="aiChart_<?= $idx ?>"></canvas>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- ===== SEGMENTAÇÃO DETALHADA ===== -->
        <?php if (!empty($segData)): ?>
        <div class="section-title">Segmentação por Categoria &mdash; <?= count($segData) ?> Itens</div>
        <div class="card">
            <div class="card-head">
                <div>
                    <div class="card-head-title">Ranking Completo de Todos os Itens</div>
                    <div class="card-head-sub"><?= count($segData) ?> categorias encontradas. Clique nos cabeçalhos para ordenar.</div>
                </div>
                <div style="font-size:12px;color:#64748b;">
                    Total: <strong style="color:#0f172a;"><?= $fmtBRL(array_sum(array_column($segData, 'value'))) ?></strong>
                </div>
            </div>
            <div class="card-body-flush" style="max-height:500px;overflow:auto;">
                <table class="detail-table" id="segTable">
                    <thead>
                        <tr>
                            <th class="rank">#</th>
                            <th style="cursor:pointer;" onclick="sortTable('segTable',1)">Item &udarr;</th>
                            <th class="num" style="cursor:pointer;" onclick="sortTable('segTable',2)">Valor (R$) &udarr;</th>
                            <th class="num" style="cursor:pointer;" onclick="sortTable('segTable',3)">% &udarr;</th>
                            <th class="bar-cell">Proporção</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($segData as $si => $sd): ?>
                        <tr>
                            <td class="rank"><?= $si + 1 ?></td>
                            <td title="<?= htmlspecialchars((string)$sd['label']) ?>"><?= htmlspecialchars((string)$sd['label']) ?></td>
                            <td class="num"><?= number_format((float)$sd['value'], 2, ',', '.') ?></td>
                            <td class="num"><?= $sd['pct'] ?>%</td>
                            <td class="bar-cell"><div class="detail-bar" style="width:<?= $sd['bar_pct'] ?>%;<?= $sd['value'] < 0 ? 'background:#dc2626;' : '' ?>"></div></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr style="background:#f8fafc;font-weight:700;">
                            <td></td>
                            <td>TOTAL</td>
                            <td class="num"><?= number_format((float)array_sum(array_column($segData, 'value')), 2, ',', '.') ?></td>
                            <td class="num">100%</td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- ===== TABELA DE DADOS COMPLETA (paginação virtual) ===== -->
        <?php if (!empty($dataHeaders) && !empty($dataRows)): ?>
        <div class="section-title">Dados Completos da Planilha (<?= number_format(count($dataRows), 0, ',', '.') ?> registros)</div>
        <div class="card">
            <div class="card-head">
                <div>
                    <div class="card-head-title">Explorador de Dados</div>
                    <div class="card-head-sub"><?= number_format(count($dataRows), 0, ',', '.') ?> registros &bull; <?= count($dataHeaders) ?> colunas &bull; Todos os dados da planilha</div>
                </div>
                <div style="display:flex;gap:8px;align-items:center;">
                    <input type="text" id="globalSearch" class="input" style="width:220px;padding:6px 10px;font-size:12px;" placeholder="Buscar em todos os campos..." oninput="debouncedFilter()">
                    <select id="perPageSelect" class="select" style="padding:4px 8px;font-size:12px;width:auto;" onchange="DT.perPage=parseInt(this.value);DT.page=0;DT.render();">
                        <option value="50">50/pág</option>
                        <option value="100" selected>100/pág</option>
                        <option value="250">250/pág</option>
                        <option value="500">500/pág</option>
                    </select>
                    <button class="btn btn-secondary" style="padding:6px 12px;font-size:12px;" onclick="resetDataFilters()">Limpar</button>
                    <button class="btn btn-primary" style="padding:6px 12px;font-size:12px;" onclick="exportCSV()">Exportar CSV</button>
                </div>
            </div>

            <?php if (!empty($filterOptions)): ?>
            <div style="padding:12px 20px;border-bottom:1px solid #f1f5f9;display:flex;flex-wrap:wrap;gap:8px;background:#fafbfc;">
                <?php foreach ($filterOptions as $colName => $opts): ?>
                <div style="min-width:140px;">
                    <div style="font-size:10px;font-weight:600;text-transform:uppercase;color:#94a3b8;margin-bottom:3px;"><?= htmlspecialchars($colName) ?></div>
                    <select class="select dataFilter" data-col="<?= htmlspecialchars($colName) ?>" style="padding:4px 8px;font-size:12px;" onchange="applyDataFilters()">
                        <option value="">Todos</option>
                        <?php foreach ($opts as $opt): ?>
                            <option value="<?= htmlspecialchars($opt) ?>"><?= htmlspecialchars(mb_strlen($opt) > 35 ? mb_substr($opt, 0, 35) . '...' : $opt) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <div class="card-body-flush" style="max-height:600px;overflow:auto;" id="dataTableWrap">
                <table class="detail-table" id="dataTable">
                    <thead>
                        <tr>
                            <th class="rank">#</th>
                            <?php foreach ($dataHeaders as $dhi => $dh): ?>
                                <th style="cursor:pointer;white-space:nowrap;" onclick="DT.sort(<?= $dhi ?>)"><?= htmlspecialchars(mb_strlen($dh) > 25 ? mb_substr($dh, 0, 25) . '..' : $dh) ?> &udarr;</th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody id="dataTableBody"></tbody>
                </table>
            </div>
            <div style="padding:10px 20px;border-top:1px solid #f1f5f9;display:flex;justify-content:space-between;align-items:center;">
                <span id="dataTableInfo" style="font-size:12px;color:#64748b;"></span>
                <div style="display:flex;gap:6px;align-items:center;">
                    <button class="btn btn-secondary" style="padding:4px 10px;font-size:11px;" onclick="DT.page=Math.max(0,DT.page-1);DT.render();">&larr; Anterior</button>
                    <span id="dataTablePageInfo" style="font-size:12px;color:#64748b;padding:4px 8px;"></span>
                    <button class="btn btn-secondary" style="padding:4px 10px;font-size:11px;" onclick="DT.page=Math.min(DT.totalPages()-1,DT.page+1);DT.render();">Próxima &rarr;</button>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- ===== CHART.JS RENDERING ===== -->
        <?php if ($hasCharts): ?>
        <script>
        (function(){
            var charts = <?= json_encode($chartsData, JSON_UNESCAPED_UNICODE) ?>;
            var brandColors = ['#2563eb','#16a34a','#ea580c','#7c3aed','#0891b2','#dc2626','#ca8a04','#4f46e5','#059669','#be185d'];
            function trunc(s,m){s=s==null?'':String(s);m=m||28;return s.length>m?s.slice(0,m-1)+'\u2026':s;}
            function pal(n){var o=[];for(var i=0;i<n;i++)o.push(brandColors[i%brandColors.length]);return o;}
            function alpha(h,a){var r=parseInt(h.slice(1,3),16),g=parseInt(h.slice(3,5),16),b=parseInt(h.slice(5,7),16);return 'rgba('+r+','+g+','+b+','+a+')';}
            charts.forEach(function(d,idx){
                var el=document.getElementById('aiChart_'+idx);
                if(!el) return;
                var ctx=el.getContext('2d');
                var type=d.type||'bar';
                if(type==='boxplot'||type==='gantt') type='bar';
                var rawL=Array.isArray(d.labels)?d.labels:[];
                var labels=rawL.map(function(l){return trunc(l,type==='pie'?40:28);});
                var vals=Array.isArray(d.values)?d.values:[];
                var colors=pal(labels.length);
                var isPie=type==='pie', isLine=type==='line';
                var bg=isPie ? colors.map(function(c){return alpha(c,0.85);})
                    : isLine ? alpha(brandColors[0],0.08)
                    : colors.map(function(c){return alpha(c,0.75);});
                var bc=isPie ? colors : isLine ? brandColors[0] : colors;
                new Chart(ctx,{
                    type: type,
                    data: {
                        labels: labels,
                        datasets: [{
                            label: d.title||'',
                            data: vals,
                            borderColor: bc,
                            backgroundColor: bg,
                            tension: 0.3,
                            fill: isLine,
                            borderWidth: isPie ? 2 : isLine ? 2.5 : 0,
                            borderRadius: type==='bar' ? 4 : 0,
                            pointRadius: isLine ? 3 : 0,
                            pointBackgroundColor: isLine ? brandColors[0] : undefined
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {display: isPie, position:'right', labels:{boxWidth:12,padding:8,font:{size:11}}},
                            title: {display: false},
                            tooltip: {
                                backgroundColor:'#0f172a',
                                padding:10,
                                cornerRadius:6,
                                callbacks: {
                                    label: function(c){
                                        var v = c.parsed.y !== undefined ? c.parsed.y : c.parsed;
                                        if(typeof v === 'number'){
                                            return c.label + ': R$ ' + v.toLocaleString('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2});
                                        }
                                        return c.label + ': ' + v;
                                    }
                                }
                            }
                        },
                        scales: isPie ? {} : {
                            x: {
                                grid:{display:false},
                                ticks:{autoSkip:true,maxTicksLimit:12,maxRotation:45,font:{size:11},
                                    callback:function(v,i){return trunc(labels[i],20);}
                                }
                            },
                            y: {
                                beginAtZero:true,
                                grid:{color:'#f1f5f9'},
                                ticks:{maxTicksLimit:6,font:{size:11},
                                    callback:function(v){return typeof v==='number'?v.toLocaleString('pt-BR'):v;}
                                }
                            }
                        }
                    }
                });
            });
        })();
        </script>
        <?php endif; ?>

        <!-- ===== TABLE SCRIPTS ===== -->
        <script>
        var sortDirs = {};
        function sortTable(tableId, colIdx){
            var table = document.getElementById(tableId);
            if(!table) return;
            var tbody = table.querySelector('tbody');
            var rows = Array.from(tbody.querySelectorAll('tr'));
            var key = tableId+'_'+colIdx;
            var dir = sortDirs[key]==='asc' ? 'desc' : 'asc';
            sortDirs[key] = dir;
            rows.sort(function(a,b){
                var av = a.cells[colIdx] ? a.cells[colIdx].textContent.trim() : '';
                var bv = b.cells[colIdx] ? b.cells[colIdx].textContent.trim() : '';
                var an = parseFloat(av.replace(/\./g,'').replace(',','.').replace('%','').replace('R$','').trim());
                var bn = parseFloat(bv.replace(/\./g,'').replace(',','.').replace('%','').replace('R$','').trim());
                if(!isNaN(an) && !isNaN(bn)) return dir==='asc' ? an-bn : bn-an;
                return dir==='asc' ? av.localeCompare(bv) : bv.localeCompare(av);
            });
            rows.forEach(function(r){ tbody.appendChild(r); });
            rows.forEach(function(r,i){ if(r.cells[0]) r.cells[0].textContent = i+1; });
        }

        <?php if (!empty($dataHeaders) && !empty($dataRows)): ?>
        // ===== VIRTUAL PAGINATION DATA TABLE =====
        var DT = {
            headers: <?= json_encode(array_values($dataHeaders), JSON_UNESCAPED_UNICODE) ?>,
            allData: <?= json_encode(array_values($dataRows), JSON_UNESCAPED_UNICODE) ?>,
            filtered: null,
            page: 0,
            perPage: 100,
            sortCol: -1,
            sortDir: 'asc',

            totalPages: function(){
                var data = this.filtered || this.allData;
                return Math.max(1, Math.ceil(data.length / this.perPage));
            },

            getFilters: function(){
                var searchEl = document.getElementById('globalSearch');
                var search = searchEl ? searchEl.value.toLowerCase().trim() : '';
                var selects = document.querySelectorAll('.dataFilter');
                var colFilters = {};
                selects.forEach(function(s){
                    var col = s.getAttribute('data-col');
                    var val = s.value;
                    if(val) colFilters[col] = val.toLowerCase();
                });
                return {search: search, colFilters: colFilters};
            },

            applyFilters: function(){
                var f = this.getFilters();
                var hasFilter = f.search !== '' || Object.keys(f.colFilters).length > 0;
                if(!hasFilter){
                    this.filtered = null;
                    this.page = 0;
                    this.render();
                    return;
                }
                var headers = this.headers;
                var result = [];
                for(var i = 0; i < this.allData.length; i++){
                    var row = this.allData[i];
                    var show = true;
                    if(f.search){
                        var rowText = '';
                        for(var c = 0; c < row.length; c++){
                            rowText += String(row[c] || '').toLowerCase() + ' ';
                        }
                        if(rowText.indexOf(f.search) === -1) show = false;
                    }
                    if(show){
                        for(var col in f.colFilters){
                            var ci = headers.indexOf(col);
                            if(ci >= 0){
                                if(String(row[ci] || '').trim().toLowerCase() !== f.colFilters[col]) show = false;
                            }
                        }
                    }
                    if(show) result.push(row);
                }
                this.filtered = result;
                this.page = 0;
                this.render();
            },

            sort: function(colIdx){
                if(this.sortCol === colIdx){
                    this.sortDir = this.sortDir === 'asc' ? 'desc' : 'asc';
                } else {
                    this.sortCol = colIdx;
                    this.sortDir = 'asc';
                }
                var dir = this.sortDir;
                var data = this.filtered || this.allData;
                data.sort(function(a,b){
                    var av = String(a[colIdx] || '').trim();
                    var bv = String(b[colIdx] || '').trim();
                    var an = parseFloat(av.replace(/\./g,'').replace(',','.').replace('%','').replace('R$','').trim());
                    var bn = parseFloat(bv.replace(/\./g,'').replace(',','.').replace('%','').replace('R$','').trim());
                    if(!isNaN(an) && !isNaN(bn)) return dir==='asc' ? an-bn : bn-an;
                    return dir==='asc' ? av.localeCompare(bv) : bv.localeCompare(av);
                });
                this.page = 0;
                this.render();
            },

            render: function(){
                var data = this.filtered || this.allData;
                var total = data.length;
                var totalAll = this.allData.length;
                var start = this.page * this.perPage;
                var end = Math.min(start + this.perPage, total);
                var tbody = document.getElementById('dataTableBody');
                if(!tbody) return;

                var html = '';
                for(var i = start; i < end; i++){
                    var row = data[i];
                    html += '<tr><td class="rank">' + (i+1) + '</td>';
                    for(var c = 0; c < row.length; c++){
                        var val = String(row[c] || '');
                        var escaped = val.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
                        html += '<td style="white-space:nowrap;max-width:220px;overflow:hidden;text-overflow:ellipsis;" title="' + escaped + '">' + escaped + '</td>';
                    }
                    html += '</tr>';
                }
                tbody.innerHTML = html;

                var info = document.getElementById('dataTableInfo');
                if(info){
                    if(this.filtered){
                        info.textContent = 'Mostrando ' + (end - start) + ' de ' + total.toLocaleString('pt-BR') + ' filtrados (total: ' + totalAll.toLocaleString('pt-BR') + ')';
                    } else {
                        info.textContent = 'Mostrando ' + (start+1).toLocaleString('pt-BR') + '-' + end.toLocaleString('pt-BR') + ' de ' + total.toLocaleString('pt-BR') + ' registros';
                    }
                }
                var pageInfo = document.getElementById('dataTablePageInfo');
                if(pageInfo){
                    pageInfo.textContent = 'Página ' + (this.page+1) + ' de ' + this.totalPages();
                }
            }
        };

        // Debounce para busca
        var _filterTimer = null;
        function debouncedFilter(){
            clearTimeout(_filterTimer);
            _filterTimer = setTimeout(function(){ DT.applyFilters(); }, 300);
        }
        function applyDataFilters(){ DT.applyFilters(); }
        function resetDataFilters(){
            var s = document.getElementById('globalSearch');
            if(s) s.value = '';
            document.querySelectorAll('.dataFilter').forEach(function(s){ s.value = ''; });
            DT.filtered = null;
            DT.page = 0;
            DT.render();
        }

        function exportCSV(){
            var data = DT.filtered || DT.allData;
            var csv = [];
            csv.push(DT.headers.map(function(h){ return '"' + h.replace(/"/g,'""') + '"'; }).join(';'));
            for(var i = 0; i < data.length; i++){
                var cols = [];
                for(var c = 0; c < data[i].length; c++){
                    cols.push('"' + String(data[i][c] || '').replace(/"/g,'""') + '"');
                }
                csv.push(cols.join(';'));
            }
            var blob = new Blob(['\uFEFF' + csv.join('\n')], {type:'text/csv;charset=utf-8;'});
            var link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = 'dados_analise_' + data.length + '_registros.csv';
            link.click();
        }

        // Renderizar primeira página ao carregar
        DT.render();
        <?php endif; ?>
        </script>

        <?php elseif (!empty($lastChartResponse)): ?>
            <div class="card" style="margin-top:16px;">
                <div class="card-head">
                    <div class="card-head-title">Resultado da IA</div>
                </div>
                <div class="card-body">
                    <details open>
                        <summary>Ver resposta completa (debug)</summary>
                        <pre><?= htmlspecialchars(json_encode($lastChartResponse, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
                    </details>
                </div>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <div style="font-size:40px;margin-bottom:12px;">📊</div>
                <div style="font-size:16px;font-weight:600;color:#475569;margin-bottom:6px;">Nenhuma análise ainda</div>
                <div>Selecione uma planilha, descreva o que deseja visualizar e clique em <strong>Gerar</strong>.</div>
            </div>
        <?php endif; ?>
    </main>
</div>
</body>
</html>
