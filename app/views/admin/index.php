<?php require_once __DIR__ . '/../../config/config.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>EasyChart - Admin Panel</title>
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
        .page-title{font-size:24px;font-weight:600;margin-bottom:4px;display:flex;align-items:center;gap:8px;}
        .page-title-icon{font-size:22px;}
        .page-subtitle{font-size:14px;color:#6b7280;margin-bottom:12px;}
        .toolbar{display:flex;gap:10px;margin-bottom:16px;flex-wrap:wrap;}
        .btn-secondary{display:inline-block;padding:8px 12px;border-radius:999px;border:1px solid #e5e7eb;font-size:13px;color:#374151;text-decoration:none;background:#ffffff;box-shadow:0 4px 10px rgba(15,23,42,0.04);}        
        .btn-secondary:hover{background:#f9fafb;}
        .kpi-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px;margin-bottom:24px;}
        .kpi-card{background:#ffffff;border-radius:14px;padding:14px 16px;display:flex;justify-content:space-between;align-items:center;box-shadow:0 12px 24px rgba(15,23,42,0.06);}
        .kpi-label{font-size:12px;text-transform:uppercase;color:#9ca3af;margin-bottom:4px;}
        .kpi-value{font-size:20px;font-weight:600;}
        .kpi-icon{width:32px;height:32px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:16px;}
        .kpi-icon.blue{background:rgba(37,99,235,0.08);color:#2563eb;}
        .kpi-icon.green{background:rgba(34,197,94,0.08);color:#16a34a;}
        .kpi-icon.purple{background:rgba(168,85,247,0.08);color:#7c3aed;}
        .kpi-icon.orange{background:rgba(249,115,22,0.08);color:#ea580c;}
        .section{background:#ffffff;border-radius:16px;box-shadow:0 18px 30px rgba(15,23,42,0.08);margin-top:18px;}
        .section-header{padding:14px 16px;border-bottom:1px solid #f3f4f6;font-weight:600;}
        table{width:100%;border-collapse:collapse;}
        th,td{padding:10px 14px;font-size:13px;text-align:left;border-bottom:1px solid #f3f4f6;}
        th{background:#f9fafb;color:#6b7280;font-weight:500;text-transform:uppercase;font-size:12px;}
        tr:last-child td{border-bottom:none;}
        .empty{padding:18px 16px;text-align:center;color:#9ca3af;font-size:14px;}
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
        <div class="page-title">
            <span class="page-title-icon">🛡️</span>
            <span><?= Lang::get('Admin Panel') ?></span>
        </div>
        <div class="page-subtitle"><?= Lang::get('System overview and user management') ?></div>

        <div class="toolbar">
            <a class="btn-secondary" href="<?= BASE_URL ?>?c=admin&a=emailSettings">
                ✉️ <?= Lang::get('Email settings') ?>
            </a>
            <a class="btn-secondary" href="<?= BASE_URL ?>?c=admin&a=asaasSettings">
                💳 Asaas settings
            </a>
            <a class="btn-secondary" href="<?= BASE_URL ?>?c=admin&a=plans">
                📦 Gerenciar planos
            </a>
        </div>

        <section class="kpi-grid">
            <div class="kpi-card">
                <div>
                    <div class="kpi-label"><?= Lang::get('Total Users') ?></div>
                    <div class="kpi-value"><?= (int)$totalUsers ?></div>
                </div>
                <div class="kpi-icon blue">👥</div>
            </div>
            <div class="kpi-card">
                <div>
                    <div class="kpi-label"><?= Lang::get('Active Users') ?></div>
                    <div class="kpi-value"><?= (int)$activeUsers ?></div>
                </div>
                <div class="kpi-icon green">✅</div>
            </div>
            <div class="kpi-card">
                <div>
                    <div class="kpi-label"><?= Lang::get('Total Charts') ?></div>
                    <div class="kpi-value"><?= (int)$totalCharts ?></div>
                </div>
                <div class="kpi-icon purple">📊</div>
            </div>
            <div class="kpi-card">
                <div>
                    <div class="kpi-label"><?= Lang::get('Spreadsheets') ?></div>
                    <div class="kpi-value"><?= (int)$totalSpreadsheets ?></div>
                </div>
                <div class="kpi-icon orange">📁</div>
            </div>
        </section>

        <section class="section">
            <div class="section-header"><?= Lang::get('User Management') ?></div>
            <?php if (empty($users)): ?>
                <div class="empty"><?= Lang::get('No users found. Users will appear here once they register for the platform') ?></div>
            <?php else: ?>
                <table>
                    <thead>
                    <tr>
                        <th><?= Lang::get('User') ?></th>
                        <th><?= Lang::get('Role') ?></th>
                        <th><?= Lang::get('Joined') ?></th>
                        <th><?= Lang::get('Charts') ?></th>
                        <th><?= Lang::get('Spreadsheets') ?></th>
                        <th><?= Lang::get('Actions') ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($users as $u): ?>
                        <tr>
                            <td>
                                <?= htmlspecialchars($u['full_name']) ?><br>
                                <span style="font-size:12px;color:#6b7280;"><?= htmlspecialchars($u['email']) ?></span>
                            </td>
                            <td><?= htmlspecialchars($u['role']) ?><?= empty($u['is_active']) ? ' ' . Lang::get('(inactive)') : '' ?></td>
                            <td><?= htmlspecialchars($u['created_at']) ?></td>
                            <td><?= (int)$u['charts_count'] ?></td>
                            <td><?= (int)$u['sheets_count'] ?></td>
                            <td>
                                <a href="<?= BASE_URL ?>?c=admin&a=view&id=<?= (int)$u['id'] ?>" style="margin-right:8px;"><?= Lang::get('View') ?></a>
                                <a href="<?= BASE_URL ?>?c=admin&a=editUserPlan&id=<?= (int)$u['id'] ?>" title="Ajustar plano" style="margin-right:8px;">💼</a>
                                <?php if ($u['id'] !== $_SESSION['user']['id']): ?>
                                    <a href="<?= BASE_URL ?>?c=admin&a=toggleRole&id=<?= (int)$u['id'] ?>" style="margin-right:8px;">
                                        <?= $u['role'] === 'super_admin' ? Lang::get('Make User') : Lang::get('Make Admin') ?>
                                    </a>
                                    <a href="<?= BASE_URL ?>?c=admin&a=toggleStatus&id=<?= (int)$u['id'] ?>" style="margin-right:8px;">
                                        <?= !empty($u['is_active']) ? Lang::get('Deactivate') : Lang::get('Activate') ?>
                                    </a>
                                    <a href="<?= BASE_URL ?>?c=admin&a=delete&id=<?= (int)$u['id'] ?>" onclick="return confirm('<?= Lang::get('Delete this user? This action cannot be undone') ?>');" style="color:#b91c1c;"><?= Lang::get('Delete') ?></a>
                                <?php else: ?>
                                    <span style="font-size:12px;color:#6b7280;"><?= Lang::get('(current user)') ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>
    </main>
</div>
</body>
</html>
