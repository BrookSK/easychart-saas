<?php require_once __DIR__ . '/../../config/config.php';

// ============================================================
// PREPARAÇÃO DE DADOS DO RELATÓRIO
// ============================================================
$rt = (string)($report['report_text'] ?? '');
$looksHtml = preg_match('/<(h[1-6]|p|div|ul|ol|li|strong|table|section)\b/i', $rt);
$userRequest = (string)($report['user_request'] ?? '');

// KPIs do analytics
$kpis = $analytics['kpis'] ?? [];
$results = $analytics['results'] ?? [];

// Segmentação a partir dos results do analytics
$segData = [];
if (!empty($results) && is_array($results)) {
    $segTotal = 0;
    foreach ($results as $k => $v) {
        if ($k === '__total__') continue;
        $segTotal += (float)$v;
    }
    $segMax = 1;
    foreach ($results as $k => $v) {
        if ($k === '__total__') continue;
        if (abs((float)$v) > $segMax) $segMax = abs((float)$v);
    }
    foreach ($results as $label => $value) {
        if ($label === '__total__') continue;
        $segData[] = [
            'label' => (string)$label,
            'value' => (float)$value,
            'pct' => $segTotal != 0 ? round(((float)$value / $segTotal) * 100, 1) : 0,
            'bar_pct' => $segMax != 0 ? round((abs((float)$value) / $segMax) * 100, 1) : 0,
        ];
    }
    usort($segData, function($a, $b) { return abs($b['value']) <=> abs($a['value']); });
}

// Fallback: segmentação dos charts se analytics vazio
if (empty($segData) && !empty($charts)) {
    foreach ($charts as $ch) {
        $chType = $ch['chart_type'] ?? $ch['type'] ?? '';
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
            usort($segData, function($a, $b) { return abs($b['value']) <=> abs($a['value']); });
            break;
        }
    }
}

// Dataset profile info
$profileCols = $datasetProfile['columns'] ?? [];
$profileRowCount = $datasetProfile['row_count'] ?? null;

// Context info
$domain = $inferredContext['domain'] ?? null;
$mainEntity = $inferredContext['main_entity'] ?? null;
$mainMetric = $inferredContext['main_metric'] ?? null;

$fmtBRL = function($v) {
    return 'R$ ' . number_format((float)$v, 2, ',', '.');
};
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>EasyChart - Relatório #<?= (int)$report['id'] ?></title>
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
        .topbar-right{font-size:13px;display:flex;align-items:center;gap:14px;}
        .topbar-right a{color:#e5e7eb;text-decoration:none;}

        .content{flex:1;padding:20px 24px 40px;width:100%;}
        .page-header{margin-bottom:20px;}
        .page-title{font-size:22px;font-weight:700;color:#0f172a;}
        .page-subtitle{font-size:13px;color:#64748b;margin-top:2px;}
        .back-link{font-size:13px;color:#2563eb;text-decoration:none;font-weight:500;display:inline-flex;align-items:center;gap:4px;margin-bottom:12px;}
        .back-link:hover{color:#1d4ed8;}

        .section-title{font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;color:#64748b;margin:24px 0 12px;display:flex;align-items:center;gap:8px;}
        .section-title::after{content:'';flex:1;height:1px;background:#e2e8f0;}

        .kpi-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:20px;}
        .kpi-card{background:#fff;border-radius:12px;padding:14px 16px;border:1px solid #e2e8f0;transition:box-shadow .2s;}
        .kpi-card:hover{box-shadow:0 4px 12px rgba(0,0,0,0.06);}
        .kpi-label{font-size:11px;text-transform:uppercase;letter-spacing:0.5px;color:#94a3b8;margin-bottom:4px;font-weight:600;}
        .kpi-value{font-size:20px;font-weight:700;color:#0f172a;}
        .kpi-sub{font-size:11px;color:#64748b;margin-top:2px;}

        .card{background:#fff;border-radius:12px;border:1px solid #e2e8f0;margin-bottom:16px;overflow:hidden;}
        .card-head{padding:16px 20px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between;}
        .card-head-title{font-size:15px;font-weight:700;color:#0f172a;}
        .card-head-sub{font-size:12px;color:#64748b;margin-top:2px;}
        .card-body{padding:20px;}
        .card-body-flush{padding:0;}

        .analysis-text{line-height:1.7;font-size:14px;color:#1e293b;}
        .analysis-text h3{font-size:16px;font-weight:700;color:#0f172a;margin:20px 0 8px;padding-bottom:6px;border-bottom:2px solid #e2e8f0;}
        .analysis-text h3:first-child{margin-top:0;}
        .analysis-text p{margin:6px 0 12px;line-height:1.7;}
        .analysis-text ul,.analysis-text ol{margin:6px 0 12px;padding-left:20px;}
        .analysis-text li{margin-bottom:4px;line-height:1.6;}
        .analysis-text strong{color:#0f172a;}

        .charts-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(420px,1fr));gap:16px;}
        .chart-card{background:#fff;border-radius:12px;border:1px solid #e2e8f0;overflow:hidden;}
        .chart-card-head{padding:14px 18px;border-bottom:1px solid #f1f5f9;}
        .chart-card-title{font-size:14px;font-weight:700;color:#0f172a;}
        .chart-card-sub{font-size:12px;color:#64748b;margin-top:2px;}
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

        .tech-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;}
        .tech-item{background:#f8fafc;border-radius:8px;padding:12px 14px;border:1px solid #e2e8f0;}
        .tech-item-label{font-size:11px;text-transform:uppercase;letter-spacing:0.5px;color:#94a3b8;font-weight:600;margin-bottom:4px;}
        .tech-item-value{font-size:14px;font-weight:700;color:#0f172a;}
        .tech-item-sub{font-size:12px;color:#64748b;margin-top:2px;}

        .meta-badge{display:inline-block;background:#eff6ff;color:#2563eb;font-size:11px;font-weight:600;padding:3px 8px;border-radius:6px;margin-right:6px;margin-bottom:4px;}

        details summary{cursor:pointer;font-size:13px;color:#2563eb;font-weight:500;padding:8px 0;}
        details summary:hover{color:#1d4ed8;}
        details pre{white-space:pre-wrap;font-size:11px;background:#0f172a;color:#e2e8f0;border-radius:8px;padding:12px;max-height:400px;overflow:auto;}

        @media(max-width:768px){
            .kpi-grid{grid-template-columns:repeat(2,1fr);}
            .charts-grid{grid-template-columns:1fr;}
            .tech-grid{grid-template-columns:1fr;}
        }
        @media print{
            .topbar,.back-link{display:none!important;}
            .content{padding:10px!important;}
            .card{break-inside:avoid;}
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
                <a href="<?= BASE_URL ?>?c=dashboard&a=index">Dashboard</a>
                <a href="<?= BASE_URL ?>?c=spreadsheets&a=index">Planilhas</a>
                <a href="<?= BASE_URL ?>?c=reports&a=index" class="active">Relatórios</a>
                <a href="<?= BASE_URL ?>?c=settings&a=index">Configurações</a>
            </nav>
        </div>
        <div class="topbar-right">
            <span>Bem-vindo, <?= isset($_SESSION['user']['full_name']) ? htmlspecialchars($_SESSION['user']['full_name']) : 'User' ?></span>
            <a href="<?= BASE_URL ?>?c=dashboard&a=logout">Logout</a>
        </div>
    </header>

    <main class="content">
        <a href="<?= BASE_URL ?>?c=reports&a=index" class="back-link">&larr; Voltar para Relatórios</a>

        <div class="page-header">
            <div class="page-title">Relatório #<?= (int)$report['id'] ?></div>
            <div class="page-subtitle">
                <span class="meta-badge"><?= htmlspecialchars($report['spreadsheet_name'] ?? 'Arquivo não identificado') ?></span>
                <span class="meta-badge"><?= htmlspecialchars($report['created_at']) ?></span>
                <?php if ($domain): ?>
                    <span class="meta-badge" style="background:#f0fdf4;color:#16a34a;"><?= htmlspecialchars($domain) ?></span>
                <?php endif; ?>
            </div>
        </div>

        <!-- ===== SOLICITAÇÃO DO USUÁRIO ===== -->
        <?php if ($userRequest !== ''): ?>
        <div class="card" style="border-left:4px solid #2563eb;">
            <div class="card-body" style="padding:14px 20px;">
                <div style="font-size:11px;font-weight:600;text-transform:uppercase;color:#94a3b8;margin-bottom:4px;">Solicitação do Usuário</div>
                <div style="font-size:15px;font-weight:600;color:#0f172a;"><?= htmlspecialchars($userRequest) ?></div>
            </div>
        </div>
        <?php endif; ?>

        <!-- ===== KPIs ===== -->
        <?php if (!empty($kpis)): ?>
        <div class="section-title">Indicadores da Análise</div>
        <div class="kpi-grid">
            <?php if (isset($kpis['total_sum'])): ?>
            <div class="kpi-card">
                <div class="kpi-label">Total</div>
                <div class="kpi-value"><?= $fmtBRL($kpis['total_sum']) ?></div>
            </div>
            <?php endif; ?>
            <?php if (isset($kpis['total_count'])): ?>
            <div class="kpi-card">
                <div class="kpi-label">Contagem</div>
                <div class="kpi-value"><?= number_format((int)$kpis['total_count'], 0, ',', '.') ?></div>
            </div>
            <?php endif; ?>
            <?php if (isset($kpis['average'])): ?>
            <div class="kpi-card">
                <div class="kpi-label">Média</div>
                <div class="kpi-value"><?= $fmtBRL($kpis['average']) ?></div>
            </div>
            <?php endif; ?>
            <?php if (isset($kpis['median'])): ?>
            <div class="kpi-card">
                <div class="kpi-label">Mediana</div>
                <div class="kpi-value"><?= $fmtBRL($kpis['median']) ?></div>
            </div>
            <?php endif; ?>
            <?php if (isset($kpis['min_value'])): ?>
            <div class="kpi-card">
                <div class="kpi-label">Menor Valor</div>
                <div class="kpi-value"><?= $fmtBRL($kpis['min_value']) ?></div>
            </div>
            <?php endif; ?>
            <?php if (isset($kpis['max_value'])): ?>
            <div class="kpi-card">
                <div class="kpi-label">Maior Valor</div>
                <div class="kpi-value"><?= $fmtBRL($kpis['max_value']) ?></div>
            </div>
            <?php endif; ?>
            <?php if (isset($kpis['total_rows_filtered'])): ?>
            <div class="kpi-card">
                <div class="kpi-label">Registros Analisados</div>
                <div class="kpi-value"><?= number_format((int)$kpis['total_rows_filtered'], 0, ',', '.') ?></div>
                <?php if (isset($kpis['total_rows_original'])): ?>
                <div class="kpi-sub">de <?= number_format((int)$kpis['total_rows_original'], 0, ',', '.') ?> originais</div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <?php if (isset($kpis['unique_groups']) && (int)$kpis['unique_groups'] > 0): ?>
            <div class="kpi-card">
                <div class="kpi-label">Grupos Únicos</div>
                <div class="kpi-value"><?= (int)$kpis['unique_groups'] ?></div>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- ===== CONTEXTO E PERFIL ===== -->
        <?php if (!empty($inferredContext) || !empty($datasetProfile)): ?>
        <div class="section-title">Contexto da Análise</div>
        <div class="card">
            <div class="card-body">
                <div class="tech-grid">
                    <?php if ($domain): ?>
                    <div class="tech-item">
                        <div class="tech-item-label">Domínio</div>
                        <div class="tech-item-value"><?= htmlspecialchars($domain) ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if ($mainEntity): ?>
                    <div class="tech-item">
                        <div class="tech-item-label">Entidade Principal</div>
                        <div class="tech-item-value"><?= htmlspecialchars($mainEntity) ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if ($mainMetric): ?>
                    <div class="tech-item">
                        <div class="tech-item-label">Métrica Principal</div>
                        <div class="tech-item-value"><?= htmlspecialchars($mainMetric) ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($inferredContext['time_axis'])): ?>
                    <div class="tech-item">
                        <div class="tech-item-label">Eixo Temporal</div>
                        <div class="tech-item-value"><?= htmlspecialchars((string)$inferredContext['time_axis']) ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if ($profileRowCount): ?>
                    <div class="tech-item">
                        <div class="tech-item-label">Total de Linhas</div>
                        <div class="tech-item-value"><?= number_format((int)$profileRowCount, 0, ',', '.') ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($profileCols) && is_array($profileCols)): ?>
                    <div class="tech-item" style="grid-column:1/-1;">
                        <div class="tech-item-label">Colunas do Dataset (<?= count($profileCols) ?>)</div>
                        <div style="display:flex;flex-wrap:wrap;gap:4px;margin-top:4px;">
                            <?php foreach ($profileCols as $col): ?>
                                <?php
                                    $colName = is_array($col) ? ($col['name'] ?? ($col['header'] ?? '')) : (string)$col;
                                    $colType = is_array($col) ? ($col['type'] ?? '') : '';
                                ?>
                                <span class="meta-badge" style="<?= $colType === 'numerica' ? 'background:#fef3c7;color:#92400e;' : '' ?>"><?= htmlspecialchars($colName) ?><?= $colType ? ' <small>(' . htmlspecialchars($colType) . ')</small>' : '' ?></span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- ===== ANÁLISE TEXTUAL ===== -->
        <?php if ($rt !== ''): ?>
        <div class="section-title">Análise Inteligente por IA</div>
        <div class="card">
            <div class="card-head">
                <div>
                    <div class="card-head-title">Análise Profunda &mdash; 5 Níveis</div>
                    <div class="card-head-sub">Análise gerada por IA com base nos dados e na solicitação.</div>
                </div>
                <button onclick="window.print()" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:6px 12px;font-size:12px;cursor:pointer;color:#475569;">Imprimir</button>
            </div>
            <div class="card-body">
                <?php if ($looksHtml): ?>
                    <div class="analysis-text"><?= $rt ?></div>
                <?php else: ?>
                    <pre style="white-space:pre-wrap;font-size:13px;background:#f8fafc;color:#1e293b;border-radius:8px;padding:16px;border:1px solid #e2e8f0;"><?= htmlspecialchars($rt) ?></pre>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- ===== GRÁFICOS ===== -->
        <?php if (!empty($charts)): ?>
        <div class="section-title">Visualizações (<?= count($charts) ?> gráficos)</div>
        <div class="charts-grid">
            <?php foreach ($charts as $idx => $c): ?>
            <div class="chart-card">
                <div class="chart-card-head">
                    <div class="chart-card-title"><?= htmlspecialchars((string)($c['title'] ?? 'Gráfico')) ?></div>
                    <?php if (!empty($c['insight'])): ?>
                        <div class="chart-card-sub"><?= htmlspecialchars((string)$c['insight']) ?></div>
                    <?php elseif (!empty($c['description'])): ?>
                        <div class="chart-card-sub"><?= htmlspecialchars((string)$c['description']) ?></div>
                    <?php endif; ?>
                </div>
                <div class="chart-card-body">
                    <canvas id="repChart_<?= (int)$idx ?>"></canvas>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- ===== SEGMENTAÇÃO DETALHADA ===== -->
        <?php if (!empty($segData)): ?>
        <div class="section-title">Segmentação Completa &mdash; <?= count($segData) ?> Itens</div>
        <div class="card">
            <div class="card-head">
                <div>
                    <div class="card-head-title">Ranking de Todos os Itens</div>
                    <div class="card-head-sub"><?= count($segData) ?> categorias. Clique nos cabeçalhos para ordenar.</div>
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
                            <td><?= htmlspecialchars((string)$sd['label']) ?></td>
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

        <!-- ===== DADOS BRUTOS (colapsável) ===== -->
        <?php if (!empty($analytics) || !empty($datasetProfile)): ?>
        <div class="section-title">Dados Técnicos</div>
        <div class="card">
            <div class="card-body">
                <?php if (!empty($analytics)): ?>
                <details>
                    <summary>Analytics completo (JSON)</summary>
                    <pre><?= htmlspecialchars(json_encode($analytics, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
                </details>
                <?php endif; ?>
                <?php if (!empty($datasetProfile)): ?>
                <details>
                    <summary>Perfil do dataset (JSON)</summary>
                    <pre><?= htmlspecialchars(json_encode($datasetProfile, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
                </details>
                <?php endif; ?>
                <?php if (!empty($inferredContext)): ?>
                <details>
                    <summary>Contexto inferido (JSON)</summary>
                    <pre><?= htmlspecialchars(json_encode($inferredContext, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
                </details>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </main>
</div>

<!-- ===== SCRIPTS ===== -->
<?php if (!empty($charts)): ?>
<script>
(function(){
    var charts = <?= json_encode($charts, JSON_UNESCAPED_UNICODE) ?>;
    var brandColors = ['#2563eb','#16a34a','#ea580c','#7c3aed','#0891b2','#dc2626','#ca8a04','#4f46e5','#059669','#be185d'];
    function trunc(s,m){s=s==null?'':String(s);m=m||28;return s.length>m?s.slice(0,m-1)+'\u2026':s;}
    function pal(n){var o=[];for(var i=0;i<n;i++)o.push(brandColors[i%brandColors.length]);return o;}
    function alpha(h,a){var r=parseInt(h.slice(1,3),16),g=parseInt(h.slice(3,5),16),b=parseInt(h.slice(5,7),16);return 'rgba('+r+','+g+','+b+','+a+')';}
    charts.forEach(function(d,idx){
        var el=document.getElementById('repChart_'+idx);
        if(!el) return;
        var ctx=el.getContext('2d');
        var type=d.chart_type||d.type||'bar';
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
                    x: {grid:{display:false},ticks:{autoSkip:true,maxTicksLimit:12,maxRotation:45,font:{size:11},callback:function(v,i){return trunc(labels[i],20);}}},
                    y: {beginAtZero:true,grid:{color:'#f1f5f9'},ticks:{maxTicksLimit:6,font:{size:11},callback:function(v){return typeof v==='number'?v.toLocaleString('pt-BR'):v;}}}
                }
            }
        });
    });
})();
</script>
<?php endif; ?>

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
</script>
</body>
</html>
