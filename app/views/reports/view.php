<?php require_once __DIR__ . '/../../config/config.php'; ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>EasyChart - Relatório</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body{margin:0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;background:#f3f4ff;color:#111827;}
        .layout{min-height:100vh;display:flex;flex-direction:column;}
        .topbar{height:56px;background:#0f172a;color:#e5e7eb;display:flex;align-items:center;justify-content:space-between;padding:0 28px;}
        .topbar-left{display:flex;align-items:center;gap:16px;}
        .logo-mark{display:flex;align-items:center;gap:6px;color:#e5e7eb;font-weight:600;}
        .logo-icon{width:24px;height:24px;border-radius:7px;background:linear-gradient(135deg,#2563eb,#4ade80);display:flex;align-items:center;justify-content:center;}
        .logo-icon-bar{width:4px;border-radius:3px;background:#ffffff;margin:0 1px;}
        .top-nav a{color:#cbd5f5;font-size:14px;margin-right:18px;text-decoration:none;}
        .top-nav a.active{color:#ffffff;font-weight:600;}
        .topbar-right{font-size:14px;display:flex;align-items:center;gap:16px;}
        .topbar-right a{color:#e5e7eb;text-decoration:none;}
        .content{flex:1;padding:24px 40px 40px;}
        .page-title{font-size:24px;font-weight:600;margin-bottom:4px;}
        .page-subtitle{font-size:14px;color:#6b7280;margin-bottom:18px;}
        .card{background:#ffffff;border-radius:16px;box-shadow:0 18px 30px rgba(15,23,42,0.08);padding:18px 20px;margin-bottom:16px;}
        .charts-grid{display:flex;flex-wrap:wrap;gap:16px;margin-top:16px;}
        .chart-card{flex:0 0 31%;max-width:31%;}
        pre{white-space:pre-wrap;font-size:12px;background:#111827;color:#e5e7eb;border-radius:10px;padding:10px 12px;max-width:100%;overflow:auto;}
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
                <a href="<?= BASE_URL ?>?c=reports&a=index" class="active">Relatórios</a>
            </nav>
        </div>
        <div class="topbar-right">
            <span>Bem-vindo, <?= isset($_SESSION['user']['full_name']) ? htmlspecialchars($_SESSION['user']['full_name']) : 'User' ?></span>
            <a href="<?= BASE_URL ?>?c=dashboard&a=logout">Logout</a>
        </div>
    </header>

    <main class="content">
        <div class="page-title">Relatório #<?= (int)$report['id'] ?></div>
        <div class="page-subtitle">
            Arquivo: <?= htmlspecialchars($report['spreadsheet_name'] ?? '-') ?>
            | Data: <?= htmlspecialchars($report['created_at']) ?>
        </div>

        <section class="card">
            <div style="font-weight:600;margin-bottom:6px;">Relatório Final</div>
            <pre><?= htmlspecialchars((string)($report['report_text'] ?? '')) ?></pre>
        </section>

        <?php if (!empty($charts)): ?>
            <section class="card">
                <div style="font-weight:600;margin-bottom:6px;">Gráficos</div>
                <div class="charts-grid">
                    <?php foreach ($charts as $idx => $c): ?>
                        <div class="card chart-card">
                            <div style="font-weight:600;margin-bottom:4px;"><?= htmlspecialchars((string)($c['title'] ?? 'Gráfico')) ?></div>
                            <div style="font-size:12px;color:#6b7280;margin-bottom:8px;"><?= htmlspecialchars((string)($c['description'] ?? '')) ?></div>
                            <div style="position:relative;height:260px;">
                                <canvas id="repChart_<?= (int)$idx ?>"></canvas>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <script>
                (function(){
                    var charts = <?= json_encode($charts, JSON_UNESCAPED_UNICODE) ?>;
                    charts.forEach(function(c, idx){
                        var el = document.getElementById('repChart_' + idx);
                        if (!el) return;
                        var ctx = el.getContext('2d');
                        var type = c.chart_type || 'bar';
                        if (type === 'boxplot' || type === 'gantt') type = 'bar';
                        if (type === 'radar') type = 'radar';
                        if (type === 'pie') type = 'pie';
                        if (type === 'line') type = 'line';
                        if (type === 'bar') type = 'bar';

                        new Chart(ctx, {
                            type: type,
                            data: {
                                labels: c.labels || [],
                                datasets: [{
                                    label: c.title || 'Chart',
                                    data: c.values || [],
                                    borderColor: '#2563eb',
                                    backgroundColor: type === 'pie' ? [
                                        '#2563eb','#22c55e','#f97316','#a855f7','#14b8a6','#ef4444','#0ea5e9','#84cc16','#eab308','#64748b'
                                    ] : 'rgba(37,99,235,0.12)',
                                    tension: 0.25,
                                    fill: type === 'line'
                                }]
                            },
                            options: {
                                responsive: true,
                                maintainAspectRatio: false,
                                plugins: {legend: {display: type === 'pie' || type === 'radar'}},
                                scales: (type === 'pie' || type === 'radar') ? {} : {x: {ticks: {autoSkip: true, maxTicksLimit: 12}}, y: {beginAtZero: false}}
                            }
                        });
                    });
                })();
            </script>
        <?php endif; ?>
    </main>
</div>
</body>
</html>
