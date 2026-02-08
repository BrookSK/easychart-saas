<?php
http_response_code(404);
echo 'Not found';
exit;
?>
<head>
    <meta charset="UTF-8">
    <title>EasyChart - Planos (Admin)</title>
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
        .lang-switcher{margin-left:20px;display:flex;align-items:center;gap:4px;font-size:13px;}
        .lang-switcher a{color:#cbd5f5;text-decoration:none;padding:2px 6px;border-radius:4px;}
        .lang-switcher a.active{color:#ffffff;font-weight:600;background:rgba(255,255,255,0.1);}
        .topbar-right{font-size:14px;display:flex;align-items:center;gap:16px;}
        .topbar-right a{color:#e5e7eb;text-decoration:none;}
        .content{flex:1;padding:24px 40px 40px;}
        .page-title{font-size:24px;font-weight:600;margin-bottom:4px;}
        .page-subtitle{font-size:14px;color:#6b7280;margin-bottom:16px;}
        .toolbar{display:flex;gap:10px;margin-bottom:16px;flex-wrap:wrap;}
        .btn-primary{display:inline-block;padding:8px 14px;border-radius:999px;border:none;background:#2563eb;color:#ffffff;font-size:13px;font-weight:500;text-decoration:none;}
        .btn-primary:hover{background:#1d4ed8;}
        .btn-secondary{display:inline-block;padding:8px 12px;border-radius:999px;border:1px solid #e5e7eb;font-size:13px;color:#374151;text-decoration:none;background:#ffffff;box-shadow:0 4px 10px rgba(15,23,42,0.04);}        
        .btn-secondary:hover{background:#f9fafb;}
        table{width:100%;border-collapse:collapse;background:#ffffff;border-radius:14px;overflow:hidden;box-shadow:0 12px 24px rgba(15,23,42,0.06);}
        th,td{padding:10px 14px;font-size:13px;text-align:left;border-bottom:1px solid #f3f4f6;}
        th{background:#f9fafb;color:#6b7280;font-weight:500;text-transform:uppercase;font-size:12px;}
        tr:last-child td{border-bottom:none;}
        .badge{display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;}
        .badge-active{background:#dcfce7;color:#166534;}
        .badge-inactive{background:#fee2e2;color:#b91c1c;}
        .actions a{margin-right:8px;font-size:13px;text-decoration:none;}
        .actions a.edit{color:#2563eb;}
        .actions a.delete{color:#b91c1c;}
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
                <a href="<?= BASE_URL ?>?c=dashboard&a=index"><?= Lang::get('Dashboard') ?></a>
                <a href="<?= BASE_URL ?>?c=spreadsheets&a=index"><?= Lang::get('Spreadsheets') ?></a>
                <a href="<?= BASE_URL ?>?c=settings&a=index"><?= Lang::get('Settings') ?></a>
                <?php if (isset($_SESSION['user']['role']) && $_SESSION['user']['role'] === 'super_admin'): ?>
                <a href="<?= BASE_URL ?>?c=admin&a=index" class="active"><?= Lang::get('AI Admin') ?></a>
                <?php endif; ?>
            </nav>
        </div>
        <div class="topbar-right">
            <div class="lang-switcher">
                <a href="<?= BASE_URL ?>?c=lang&a=switch&lang=pt" class="<?= Lang::getCurrentLang() === 'pt' ? 'active' : '' ?>">PT</a>
                <span>|</span>
                <a href="<?= BASE_URL ?>?c=lang&a=switch&lang=en" class="<?= Lang::getCurrentLang() === 'en' ? 'active' : '' ?>">EN</a>
            </div>
            <span><?= Lang::get('Welcome') ?>, <?= isset($_SESSION['user']['full_name']) ? htmlspecialchars($_SESSION['user']['full_name']) : 'User' ?></span>
            <a href="<?= BASE_URL ?>?c=dashboard&a=logout"><?= Lang::get('Logout') ?></a>
        </div>
    </header>

    <main class="content">
        <div class="page-title">Planos</div>
        <div class="page-subtitle">Gerencie os planos disponíveis (limites, preços e status).</div>

        <div class="toolbar">
            <a class="btn-secondary" href="<?= BASE_URL ?>?c=admin&a=index">&larr; Voltar ao painel</a>
            <a class="btn-primary" href="<?= BASE_URL ?>?c=admin&a=editPlan">+ Novo plano</a>
        </div>

        <table>
            <thead>
            <tr>
                <th>ID</th>
                <th>Nome</th>
                <th>Slug</th>
                <th>Preço</th>
                <th>Uploads/mês</th>
                <th>Gráficos/mês</th>
                <th>Tokens/mês</th>
                <th>Status</th>
                <th>Ações</th>
            </tr>
            </thead>
            <tbody>
            <?php if (empty($plans)): ?>
                <tr><td colspan="8" style="text-align:center;color:#9ca3af;">Nenhum plano cadastrado.</td></tr>
            <?php else: ?>
                <?php foreach ($plans as $p): ?>
                    <tr>
                        <td><?= (int)$p['id'] ?></td>
                        <td><?= htmlspecialchars($p['name']) ?></td>
                        <td><?= htmlspecialchars($p['slug']) ?></td>
                        <td>R$ <?= number_format((int)$p['price_cents'] / 100, 2, ',', '.') ?></td>
                        <td><?= $p['monthly_spreadsheet_limit'] === null ? 'Ilimitado' : (int)$p['monthly_spreadsheet_limit'] ?></td>
                        <td><?= $p['monthly_chart_limit'] === null ? 'Ilimitado' : (int)$p['monthly_chart_limit'] ?></td>
                        <td><?= !array_key_exists('monthly_token_limit', $p) || $p['monthly_token_limit'] === null ? 'Ilimitado' : (int)$p['monthly_token_limit'] ?></td>
                        <td>
                            <?php if (!empty($p['is_active'])): ?>
                                <span class="badge badge-active">Ativo</span>
                            <?php else: ?>
                                <span class="badge badge-inactive">Inativo</span>
                            <?php endif; ?>
                        </td>
                        <td class="actions">
                            <a class="edit" href="<?= BASE_URL ?>?c=admin&a=editPlan&id=<?= (int)$p['id'] ?>">Editar</a>
                            <a class="delete" href="<?= BASE_URL ?>?c=admin&a=deletePlan&id=<?= (int)$p['id'] ?>" onclick="return confirm('Excluir este plano?');">Excluir</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </main>
</div>
</body>
</html>
