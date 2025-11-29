<?php require_once __DIR__ . '/../../config/config.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>EasyChart - Spreadsheets</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body{
            margin:0;
            font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;
            background:#f3f4ff;
            color:#111827;
        }
        .layout{min-height:100vh;display:flex;flex-direction:column;}
        .topbar{
            height:56px;background:#0f172a;color:#e5e7eb;display:flex;align-items:center;justify-content:space-between;padding:0 28px;
        }
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
        .upload-card{
            background:#ffffff;border-radius:18px;padding:26px 26px;box-shadow:0 18px 30px rgba(15,23,42,0.08);max-width:640px;margin-bottom:32px;
        }
        .upload-title{font-weight:600;margin-bottom:4px;}
        .upload-subtitle{font-size:13px;color:#6b7280;margin-bottom:16px;}
        .upload-box{display:flex;align-items:center;gap:16px;}
        .btn-primary{display:inline-block;padding:9px 18px;border-radius:9px;border:none;background:#2563eb;color:#ffffff;font-size:14px;font-weight:500;cursor:pointer;}
        .btn-primary:hover{background:#1d4ed8;}
        .upload-note{font-size:12px;color:#9ca3af;margin-top:8px;}
        .empty-state{margin-top:40px;text-align:center;color:#9ca3af;font-size:14px;}
        table{width:100%;border-collapse:collapse;margin-top:24px;background:#ffffff;border-radius:14px;overflow:hidden;box-shadow:0 12px 24px rgba(15,23,42,0.06);}
        th,td{padding:10px 14px;font-size:13px;text-align:left;border-bottom:1px solid #f3f4f6;}
        th{background:#f9fafb;color:#6b7280;font-weight:500;text-transform:uppercase;font-size:12px;}
        tr:last-child td{border-bottom:none;}
        .actions a{margin-right:8px;font-size:13px;text-decoration:none;}
        .actions a.download{color:#2563eb;}
        .actions a.delete{color:#b91c1c;}
        .flash-error{margin-bottom:12px;font-size:13px;color:#b91c1c;background:#fee2e2;border-radius:8px;padding:6px 8px;max-width:640px;}
        .flash-success{margin-bottom:12px;font-size:13px;color:#166534;background:#dcfce7;border-radius:8px;padding:6px 8px;max-width:640px;}
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
                <a href="<?= BASE_URL ?>?c=spreadsheets&a=index" class="active"><?= Lang::get('Spreadsheets') ?></a>
                <a href="<?= BASE_URL ?>?c=settings&a=index"><?= Lang::get('Settings') ?></a>
                <?php if (isset($_SESSION['user']['role']) && $_SESSION['user']['role'] === 'super_admin'): ?>
                <a href="<?= BASE_URL ?>?c=admin&a=index"><?= Lang::get('AI Admin') ?></a>
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
        <div class="page-title"><?= Lang::get('Spreadsheets') ?></div>
        <div class="page-subtitle"><?= Lang::get('Manage your spreadsheets and upload new files') ?></div>

        <?php if (!empty($error)): ?>
            <div class="flash-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <?php if (!empty($success)): ?>
            <div class="flash-success"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        <section class="upload-card">
            <div class="upload-title"><?= Lang::get('Upload CSV File') ?></div>
            <form method="post" enctype="multipart/form-data">
                <div class="upload-box">
                    <input type="file" name="spreadsheet" accept=".csv" required>
                    <button class="btn-primary" type="submit"><?= Lang::get('Choose File') ?></button>
                </div>
                <div class="upload-note">
                    <?= Lang::get('Supported formats: CSV (comma-separated)') ?> – <?= Lang::get('Supports CSV files up to 10MB') ?>.
                </div>
            </form>
        </section>

        <?php if (empty($spreadsheets)): ?>
            <div class="empty-state">
                <?= Lang::get('No spreadsheets uploaded') ?><br>
                <?= Lang::get('Upload your first CSV file to get started with data visualization') ?>.
            </div>
        <?php else: ?>
            <table>
                <thead>
                <tr>
                    <th><?= Lang::get('File Name') ?></th>
                    <th><?= Lang::get('Size') ?></th>
                    <th><?= Lang::get('Uploaded At') ?></th>
                    <th><?= Lang::get('Actions') ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($spreadsheets as $sheet): ?>
                    <tr>
                        <td><?= htmlspecialchars($sheet['original_name']) ?></td>
                        <td><?= number_format($sheet['size_bytes'] / 1024, 1) ?> KB</td>
                        <td><?= htmlspecialchars($sheet['created_at']) ?></td>
                        <td class="actions">
                            <a class="download" href="<?= BASE_URL ?>?c=spreadsheets&a=download&id=<?= (int)$sheet['id'] ?>"><?= Lang::get('Download') ?></a>
                            <a class="delete" href="<?= BASE_URL ?>?c=spreadsheets&a=delete&id=<?= (int)$sheet['id'] ?>" onclick="return confirm('<?= Lang::get('Delete this spreadsheet?') ?>');"><?= Lang::get('Delete') ?></a>
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
