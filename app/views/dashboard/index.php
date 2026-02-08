<?php require_once __DIR__ . '/../../config/config.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>EasyChart - Dashboard</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body{
            margin:0;
            font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;
            background:#f3f4ff;
            color:#111827;
        }
        .layout{
            min-height:100vh;
            display:flex;
            flex-direction:column;
        }
        .topbar{
            height:56px;
            background:#0f172a;
            color:#e5e7eb;
            display:flex;
            align-items:center;
            justify-content:space-between;
            padding:0 28px;
        }
        .topbar-left{
            display:flex;
            align-items:center;
            gap:16px;
        }
        .logo-mark{
            display:flex;
            align-items:center;
            gap:6px;
            color:#e5e7eb;
            font-weight:600;
        }
        .logo-icon{
            width:24px;
            height:24px;
            border-radius:7px;
            background:linear-gradient(135deg,#2563eb,#4ade80);
            display:flex;
            align-items:center;
            justify-content:center;
        }
        .logo-icon-bar{
            width:4px;
            border-radius:3px;
            background:#ffffff;
            margin:0 1px;
        }
        .top-nav a{
            color:#cbd5f5;
            font-size:14px;
            margin-right:18px;
            text-decoration:none;
        }
        .top-nav a.active{
            color:#ffffff;
            font-weight:600;
        }
        .lang-switcher{
            margin-left:20px;
            display:flex;
            align-items:center;
            gap:4px;
            font-size:13px;
        }
        .lang-switcher a{
            color:#cbd5f5;
            text-decoration:none;
            padding:2px 6px;
            border-radius:4px;
        }
        .lang-switcher a.active{
            color:#ffffff;
            font-weight:600;
            background:rgba(255,255,255,0.1);
        }
        .topbar-right{
            font-size:14px;
            display:flex;
            align-items:center;
            gap:16px;
        }
        .topbar-right a{
            color:#e5e7eb;
            text-decoration:none;
        }
        .content{
            flex:1;
            padding:24px 40px 40px;
        }
        .page-title{
            font-size:24px;
            font-weight:600;
            margin-bottom:4px;
        }
        .page-subtitle{
            font-size:14px;
            color:#6b7280;
            margin-bottom:24px;
        }
        .kpi-grid{
            display:grid;
            grid-template-columns:repeat(4,minmax(0,1fr));
            gap:16px;
            margin-bottom:24px;
        }
        .kpi-card{
            background:#ffffff;
            border-radius:14px;
            padding:14px 16px;
            display:flex;
            justify-content:space-between;
            align-items:center;
            box-shadow:0 12px 24px rgba(15,23,42,0.06);
        }
        .kpi-label{
            font-size:12px;
            text-transform:uppercase;
            color:#9ca3af;
            margin-bottom:4px;
        }
        .kpi-value{
            font-size:20px;
            font-weight:600;
        }
        .kpi-icon{
            width:32px;
            height:32px;
            border-radius:10px;
            display:flex;
            align-items:center;
            justify-content:center;
            font-size:16px;
        }
        .kpi-icon.blue{background:rgba(37,99,235,0.08);color:#2563eb;}
        .kpi-icon.green{background:rgba(34,197,94,0.08);color:#16a34a;}
        .kpi-icon.purple{background:rgba(168,85,247,0.08);color:#7c3aed;}
        .kpi-icon.orange{background:rgba(249,115,22,0.08);color:#ea580c;}
        .card-large{
            background:#ffffff;
            border-radius:16px;
            padding:18px 20px 18px;
            box-shadow:0 18px 30px rgba(15,23,42,0.08);
        }
        .charts-grid{
            display:flex;
            flex-wrap:wrap;
            gap:16px;
            margin-top:24px;
        }
        .chart-card{
            flex:0 0 30%;
            max-width:30%;
        }
        .card-header{
            display:flex;
            align-items:center;
            justify-content:space-between;
            margin-bottom:16px;
        }
        .card-title{
            font-weight:600;
        }
        .card-subtitle{
            font-size:13px;
            color:#6b7280;
        }
        .field-label{
            font-size:13px;
            font-weight:500;
            color:#374151;
            margin-bottom:6px;
        }
        .field-row{
            display:flex;
            gap:12px;
            margin-bottom:12px;
        }
        .field-row > div{
            flex:1;
        }
        .input{
            width:100%;
            border-radius:10px;
            border:1px solid #e5e7eb;
            padding:9px 11px;
            font-size:14px;
            outline:none;
        }
        .input:focus{
            border-color:#2563eb;
            box-shadow:0 0 0 1px rgba(37,99,235,0.15);
        }
        .select{
            width:100%;
            border-radius:10px;
            border:1px solid #e5e7eb;
            padding:9px 11px;
            font-size:14px;
            outline:none;
            background:#ffffff;
        }
        .btn-generate{
            margin-top:22px;
            padding:9px 18px;
            background:#2563eb;
            border-radius:9px;
            border:none;
            color:#ffffff;
            font-size:14px;
            font-weight:500;
            cursor:pointer;
        }
        .btn-generate:hover{background:#1d4ed8;}
        .helper-box{
            margin-top:12px;
            border-radius:10px;
            background:#eff4ff;
            padding:10px 12px;
            font-size:13px;
            color:#374151;
        }
        .empty-state{
            margin-top:40px;
            text-align:center;
            color:#9ca3af;
            font-size:14px;
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
        <div class="page-title"><?= Lang::get('Dashboard') ?></div>
        <div class="page-subtitle"><?= Lang::get('Generate charts from your spreadsheets using AI') ?></div>

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
            <div style="margin-bottom:10px;font-size:13px;color:#b91c1c;background:#fee2e2;border-radius:8px;padding:6px 8px;max-width:720px;">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>
        <?php if (!empty($success)): ?>
            <div style="margin-bottom:10px;font-size:13px;color:#166534;background:#dcfce7;border-radius:8px;padding:6px 8px;max-width:720px;">
                <?= htmlspecialchars($success) ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($analysisReportId) || !empty($analysisReportText)): ?>
            <section class="card-large" style="margin-bottom:16px;max-width:980px;">
                <div class="card-header">
                    <div>
                        <div class="card-title">Relatório técnico gerado</div>
                        <div class="card-subtitle">Relatório no formato fixo (ETAPAS 1–9).</div>
                    </div>
                    <?php if (!empty($analysisReportId)): ?>
                        <a href="<?= BASE_URL ?>?c=reports&a=view&id=<?= (int)$analysisReportId ?>" style="font-size:13px;color:#2563eb;text-decoration:none;">Ver relatório</a>
                    <?php endif; ?>
                </div>
                <?php if (!empty($analysisReportText)): ?>
                    <pre style="white-space:pre-wrap;font-size:12px;background:#111827;color:#e5e7eb;border-radius:10px;padding:10px 12px;max-width:100%;overflow:auto;">
<?= htmlspecialchars(mb_strimwidth((string)$analysisReportText, 0, 3000, "\n...")) ?>
                    </pre>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <section class="card-large">
            <div class="card-header">
                <div>
                    <div class="card-title"><?= Lang::get('AI Chart Generator') ?></div>
                    <div class="card-subtitle"><?= Lang::get('Tell me what you want to visualize and I\'ll create the perfect chart for you') ?></div>
                </div>
            </div>

            <form method="post" enctype="multipart/form-data">
                <div class="field-row">
                    <div>
                        <div class="field-label"><?= Lang::get('Select Spreadsheet') ?></div>
                        <select class="select" name="spreadsheet_id">
                            <option value=""><?= Lang::get('Choose a spreadsheet...') ?></option>
                            <?php if (!empty($spreadsheets)): ?>
                                <?php foreach ($spreadsheets as $sheet): ?>
                                    <option value="<?= (int)$sheet['id'] ?>"><?= htmlspecialchars($sheet['original_name']) ?></option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </div>
                </div>

                <div class="field-row">
                    <div>
                        <div class="field-label"><?= Lang::get('Or upload a new spreadsheet') ?></div>
                        <input type="file" name="spreadsheet" class="input">
                    </div>
                </div>

                <div class="field-row">
                    <div>
                        <div class="field-label"><?= Lang::get('What do you want to visualize?') ?></div>
                        <input class="input" name="prompt" placeholder="<?= Lang::get('Show sales trend over time') ?> <?= Lang::get('or') ?> <?= Lang::get('Compare revenue by region') ?>">
                    </div>
                </div>

                <button class="btn-generate" type="submit"><?= Lang::get('Generate') ?></button>

                <div class="helper-box">
                    <?= Lang::get('Upload your first spreadsheet to start generating charts with AI') ?>.
                </div>
            </form>
        </section>
        <?php if (!empty($chartsData)): ?>

        <!-- Vários gráficos gerados pela IA -->
            <div class="charts-grid">
            <?php foreach ($chartsData as $idx => $chart): ?>
                <section class="card-large chart-card" style="max-height:500px;">
                    <div class="card-header">
                        <div>
                            <div class="card-title"><?= htmlspecialchars($chart['title'] ?? 'Generated chart') ?></div>
                            <div class="card-subtitle">Visualization based on your file and AI suggestions.</div>
                        </div>
                    </div>
                    <div style="position:relative; height:300px; max-height:500px;">
                        <canvas id="aiChart_<?= $idx ?>"></canvas>
                    </div>
                </section>
            <?php endforeach; ?>
            </div>
            <script>
                (function(){
                    var charts = <?= json_encode($chartsData, JSON_UNESCAPED_UNICODE) ?>;
                    charts.forEach(function(data, idx){
                        var canvasId = 'aiChart_' + idx;
                        var el = document.getElementById(canvasId);
                        if (!el) return;
                        var ctx = el.getContext('2d');
                        var type = data.type || 'line';
                        if (type === 'boxplot' || type === 'gantt') type = 'bar';
                        new Chart(ctx, {
                            type: type,
                            data: {
                                labels: data.labels,
                                datasets: [{
                                    label: data.title || 'AI Chart',
                                    data: data.values,
                                    borderColor: '#2563eb',
                                    backgroundColor: type === 'pie' ? [
                                        '#2563eb','#22c55e','#f97316','#a855f7','#14b8a6','#ef4444','#0ea5e9','#84cc16','#eab308','#64748b'
                                    ] : 'rgba(37,99,235,0.12)',
                                    tension: 0.25,
                                    fill: type === 'line',
                                }]
                            },
                            options: {
                                responsive: true,
                                maintainAspectRatio: false,
                                plugins: {
                                    legend: {display: type === 'pie' || type === 'radar'},
                                    title: {display: false}
                                },
                                scales: {
                                    x: (type === 'pie' || type === 'radar') ? {} : {ticks: {autoSkip: true, maxTicksLimit: 12}},
                                    y: (type === 'pie' || type === 'radar') ? {} : {beginAtZero: false}
                                }
                            }
                        });
                    });
                })();
            </script>
        <?php elseif (!empty($lastChartResponse)): ?>
            <div class="empty-state" style="margin-top:24px; text-align:left;">
                <div style="font-weight:600;margin-bottom:4px;">Last AI result (debug view)</div>
                <pre style="white-space:pre-wrap;font-size:12px;background:#111827;color:#e5e7eb;border-radius:10px;padding:10px 12px;max-width:100%;overflow:auto;">
<?= htmlspecialchars(json_encode($lastChartResponse, JSON_PRETTY_PRINT)) ?>
                </pre>
            </div>
        <?php else: ?>
            <div class="empty-state">
                No charts yet. Upload a spreadsheet and generate your first chart to get started.
            </div>
        <?php endif; ?>
    </main>
</div>
</body>
</html>
