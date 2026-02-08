<?php require_once __DIR__ . '/../../config/config.php'; ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>EasyChart - Relatórios</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
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
        .page-subtitle{font-size:14px;color:#6b7280;margin-bottom:24px;}
        table{width:100%;border-collapse:collapse;margin-top:24px;background:#ffffff;border-radius:14px;overflow:hidden;box-shadow:0 12px 24px rgba(15,23,42,0.06);}
        th,td{padding:10px 14px;font-size:13px;text-align:left;border-bottom:1px solid #f3f4f6;}
        th{background:#f9fafb;color:#6b7280;font-weight:500;text-transform:uppercase;font-size:12px;}
        tr:last-child td{border-bottom:none;}
        .empty{margin-top:40px;text-align:center;color:#9ca3af;font-size:14px;}
        .actions a{margin-right:8px;font-size:13px;text-decoration:none;color:#2563eb;}
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
                <a href="<?= BASE_URL ?>?c=spreadsheets&a=index">Spreadsheets</a>
                <a href="<?= BASE_URL ?>?c=settings&a=index">Settings</a>
                <a href="<?= BASE_URL ?>?c=reports&a=index" class="active">Relatórios</a>
            </nav>
        </div>
        <div class="topbar-right">
            <span>Bem-vindo, <?= isset($_SESSION['user']['full_name']) ? htmlspecialchars($_SESSION['user']['full_name']) : 'User' ?></span>
            <a href="<?= BASE_URL ?>?c=dashboard&a=logout">Logout</a>
        </div>
    </header>

    <main class="content">
        <div class="page-title">Relatórios</div>
        <div class="page-subtitle">Histórico das análises geradas.</div>

        <?php if (empty($reports)): ?>
            <div class="empty">Nenhum relatório gerado ainda.</div>
        <?php else: ?>
            <table>
                <thead>
                <tr>
                    <th>ID</th>
                    <th>Data</th>
                    <th>Arquivo</th>
                    <th>Pedido</th>
                    <th>Ações</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($reports as $r): ?>
                    <tr>
                        <td><?= (int)$r['id'] ?></td>
                        <td><?= htmlspecialchars($r['created_at']) ?></td>
                        <td><?= htmlspecialchars($r['spreadsheet_name'] ?? '-') ?></td>
                        <td><?= htmlspecialchars(mb_strimwidth((string)($r['user_request'] ?? ''), 0, 80, '...')) ?></td>
                        <td class="actions">
                            <a href="<?= BASE_URL ?>?c=reports&a=view&id=<?= (int)$r['id'] ?>">Ver</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </main>
</div>
</body>
</html>
