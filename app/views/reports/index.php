<?php require_once __DIR__ . '/../../config/config.php'; ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>EasyChart - Relatórios</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
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
        .page-title{font-size:22px;font-weight:700;color:#0f172a;}
        .page-subtitle{font-size:13px;color:#64748b;margin-top:2px;margin-bottom:20px;}
        .card{background:#fff;border-radius:12px;border:1px solid #e2e8f0;overflow:hidden;}
        .card-head{padding:16px 20px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between;}
        .card-head-title{font-size:15px;font-weight:700;color:#0f172a;}
        .card-head-sub{font-size:12px;color:#64748b;margin-top:2px;}
        .report-table{width:100%;border-collapse:collapse;font-size:13px;}
        .report-table thead{background:#f8fafc;}
        .report-table th{padding:10px 14px;text-align:left;font-weight:600;color:#475569;border-bottom:2px solid #e2e8f0;font-size:12px;text-transform:uppercase;letter-spacing:0.3px;}
        .report-table td{padding:10px 14px;border-bottom:1px solid #f1f5f9;color:#334155;}
        .report-table tbody tr:hover{background:#f8fafc;cursor:pointer;}
        .report-table .id-col{width:60px;color:#94a3b8;font-weight:600;}
        .report-table .date-col{width:160px;white-space:nowrap;}
        .report-table .request-col{max-width:400px;}
        .badge{display:inline-block;background:#eff6ff;color:#2563eb;font-size:11px;font-weight:600;padding:2px 8px;border-radius:6px;}
        .btn-view{display:inline-block;background:#2563eb;color:#fff;font-size:12px;font-weight:600;padding:5px 12px;border-radius:6px;text-decoration:none;transition:background .15s;}
        .btn-view:hover{background:#1d4ed8;}
        .empty-state{text-align:center;color:#94a3b8;font-size:14px;padding:60px 20px;}
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
        <div class="page-title">Relatórios</div>
        <div class="page-subtitle">Histórico completo das análises geradas por IA. <?= !empty($reports) ? count($reports) . ' relatório(s) encontrado(s).' : '' ?></div>

        <?php if (empty($reports)): ?>
            <div class="empty-state">
                <div style="font-size:40px;margin-bottom:12px;">📋</div>
                <div style="font-size:16px;font-weight:600;color:#475569;margin-bottom:6px;">Nenhum relatório gerado ainda</div>
                <div>Vá ao <a href="<?= BASE_URL ?>?c=dashboard&a=index" style="color:#2563eb;text-decoration:none;font-weight:600;">Dashboard</a> para gerar sua primeira análise.</div>
            </div>
        <?php else: ?>
            <div class="card">
                <div class="card-head">
                    <div>
                        <div class="card-head-title">Histórico de Análises</div>
                        <div class="card-head-sub"><?= count($reports) ?> relatório(s) &bull; Clique em "Ver" para abrir o relatório completo</div>
                    </div>
                </div>
                <table class="report-table">
                    <thead>
                    <tr>
                        <th class="id-col">#</th>
                        <th class="date-col">Data</th>
                        <th>Arquivo</th>
                        <th class="request-col">Solicitação</th>
                        <th style="width:80px;text-align:center;">Ação</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($reports as $r): ?>
                        <tr onclick="window.location='<?= BASE_URL ?>?c=reports&a=view&id=<?= (int)$r['id'] ?>'">
                            <td class="id-col"><?= (int)$r['id'] ?></td>
                            <td class="date-col"><?= htmlspecialchars($r['created_at']) ?></td>
                            <td><span class="badge"><?= htmlspecialchars($r['spreadsheet_name'] ?? '-') ?></span></td>
                            <td class="request-col"><?= htmlspecialchars(mb_strimwidth((string)($r['user_request'] ?? ''), 0, 100, '...')) ?></td>
                            <td style="text-align:center;">
                                <a class="btn-view" href="<?= BASE_URL ?>?c=reports&a=view&id=<?= (int)$r['id'] ?>">Ver</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </main>
</div>
</body>
</html>
