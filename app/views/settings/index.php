<?php require_once __DIR__ . '/../../config/config.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>EasyChart - Settings</title>
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
        .page-subtitle{font-size:14px;color:#6b7280;margin-bottom:24px;}
        .section{background:#ffffff;border-radius:16px;padding:18px 20px 20px;box-shadow:0 18px 30px rgba(15,23,42,0.08);margin-bottom:18px;max-width:720px;}
        .section-title{font-weight:600;margin-bottom:4px;}
        .section-subtitle{font-size:13px;color:#6b7280;margin-bottom:14px;}
        label{display:block;font-size:13px;font-weight:500;color:#374151;margin-bottom:6px;}
        .input{width:100%;border-radius:10px;border:1px solid #e5e7eb;padding:9px 11px;font-size:14px;outline:none;margin-bottom:10px;}
        .input:focus{border-color:#2563eb;box-shadow:0 0 0 1px rgba(37,99,235,0.15);}
        .checkbox-row{display:flex;align-items:center;margin-bottom:6px;font-size:13px;color:#374151;}
        .checkbox-row input{margin-right:8px;}
        .btn-primary{margin-top:16px;padding:9px 18px;background:#2563eb;border-radius:9px;border:none;color:#ffffff;font-size:14px;font-weight:500;cursor:pointer;}
        .btn-primary:hover{background:#1d4ed8;}
        .flash-error{margin-bottom:12px;font-size:13px;color:#b91c1c;background:#fee2e2;border-radius:8px;padding:6px 8px;max-width:720px;}
        .flash-success{margin-bottom:12px;font-size:13px;color:#166534;background:#dcfce7;border-radius:8px;padding:6px 8px;max-width:720px;}
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
                <a href="<?= BASE_URL ?>?c=settings&a=index" class="active"><?= Lang::get('Settings') ?></a>
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
        <div class="page-title"><?= Lang::get('Settings') ?></div>
        <div class="page-subtitle"><?= Lang::get('Manage your account preferences and API configurations') ?></div>

        <?php if (!empty($error)): ?>
            <div class="flash-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <?php if (!empty($success)): ?>
            <div class="flash-success"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        <form method="post">
            <section class="section">
                <div class="section-title"><?= Lang::get('Profile Information') ?></div>
                <div class="section-subtitle"><?= Lang::get('Update your personal details') ?></div>

                <label><?= Lang::get('Full Name') ?></label>
                <input class="input" name="full_name" value="<?= htmlspecialchars($userRow['full_name'] ?? '') ?>" required>

                <label><?= Lang::get('Email Address') ?></label>
                <input class="input" name="email" value="<?= htmlspecialchars($userRow['email'] ?? '') ?>" disabled>
            </section>

            <?php if (isset($_SESSION['user']['role']) && $_SESSION['user']['role'] === 'super_admin'): ?>
            <section class="section">
                <div class="section-title"><?= Lang::get('API Configuration') ?></div>
                <div class="section-subtitle"><?= Lang::get('Configure your API key used for AI chart generation') ?></div>

                <label><?= Lang::get('OpenAI API Key') ?></label>
                <input class="input" name="api_key" value="<?= htmlspecialchars($apiKeyValue) ?>" placeholder="sk-...">
            </section>
            <?php endif; ?>

            <section class="section">
                <div class="section-title"><?= Lang::get('Notification Preferences') ?></div>
                <div class="section-subtitle"><?= Lang::get('Choose how you want to be notified') ?></div>

                <div class="checkbox-row">
                    <input type="checkbox" name="notify_email" <?= !empty($userSettings['notification_email_enabled']) ? 'checked' : '' ?>>
                    <span><?= Lang::get('Email notifications for chart generation') ?></span>
                </div>
                <div class="checkbox-row">
                    <input type="checkbox" name="notify_weekly" <?= !empty($userSettings['notification_weekly_summary']) ? 'checked' : '' ?>>
                    <span><?= Lang::get('Weekly usage summary') ?></span>
                </div>
                <div class="checkbox-row">
                    <input type="checkbox" name="notify_product" <?= !empty($userSettings['notification_product_updates']) ? 'checked' : '' ?>>
                    <span><?= Lang::get('Product updates and announcements') ?></span>
                </div>
            </section>

            <button class="btn-primary" type="submit"><?= Lang::get('Save Changes') ?></button>
        </form>
    </main>
</div>
</body>
</html>
