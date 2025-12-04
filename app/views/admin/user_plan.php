<?php require_once __DIR__ . '/../../config/config.php'; ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>EasyChart - Ajustar plano do usuário</title>
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
        .card{background:#ffffff;border-radius:16px;box-shadow:0 18px 30px rgba(15,23,42,0.08);padding:18px 20px;max-width:720px;}
        .card-title{font-size:20px;font-weight:600;margin-bottom:4px;}
        .card-subtitle{font-size:14px;color:#6b7280;margin-bottom:16px;}
        .row{margin-bottom:10px;font-size:14px;}
        .label{font-weight:500;color:#374151;margin-right:6px;}
        .field{margin-top:4px;}
        .input, .select{width:100%;border-radius:10px;border:1px solid #e5e7eb;padding:8px 10px;font-size:14px;}
        .input:focus, .select:focus{border-color:#2563eb;outline:none;}
        .actions{margin-top:18px;display:flex;gap:10px;}
        .btn-primary{padding:8px 14px;border-radius:999px;border:none;background:#2563eb;color:#ffffff;font-size:13px;font-weight:500;cursor:pointer;}
        .btn-primary:hover{background:#1d4ed8;}
        .btn-secondary{padding:8px 12px;border-radius:999px;border:1px solid #e5e7eb;font-size:13px;color:#374151;text-decoration:none;background:#ffffff;}
        .btn-secondary:hover{background:#f9fafb;}
        .checkbox-row{display:flex;align-items:center;gap:8px;margin-top:8px;}
        .error{margin-bottom:10px;font-size:13px;color:#b91c1c;background:#fee2e2;border-radius:8px;padding:6px 8px;}
        .success{margin-bottom:10px;font-size:13px;color:#166534;background:#dcfce7;border-radius:8px;padding:6px 8px;}
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
                <a href="<?= BASE_URL ?>?c=admin&a=index" class="active">Admin Panel</a>
            </nav>
        </div>
        <div class="topbar-right">
            <span>Bem-vindo, <?= isset($_SESSION['user']['full_name']) ? htmlspecialchars($_SESSION['user']['full_name']) : 'User' ?></span>
            <a href="<?= BASE_URL ?>?c=dashboard&a=logout">Logout</a>
        </div>
    </header>

    <main class="content">
        <div class="card">
            <div class="card-title">Ajustar plano do usuário</div>
            <div class="card-subtitle">Atualize manualmente o plano associado a esta conta.</div>

            <?php if (!empty($error)): ?>
                <div class="error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <?php if (!empty($success)): ?>
                <div class="success">Plano atualizado com sucesso.</div>
            <?php endif; ?>

            <div class="row"><span class="label">Usuário:</span> <?= htmlspecialchars($userPlan['full_name']) ?> (<?= htmlspecialchars($userPlan['email']) ?>)</div>

            <form method="post">
                <input type="hidden" name="id" value="<?= (int)$userPlan['id'] ?>">

                <div class="row field">
                    <span class="label">Plano atual:</span>
                    <?php
                    $currentPlanName = 'Nenhum (Free)';
                    if (!empty($userPlan['plan_id']) && !empty($plans)) {
                        foreach ($plans as $p) {
                            if ((int)$p['id'] === (int)$userPlan['plan_id']) {
                                $currentPlanName = htmlspecialchars($p['name']) . ' (' . htmlspecialchars($p['slug']) . ')';
                                break;
                            }
                        }
                    }
                    ?>
                    <div><?= $currentPlanName ?> – status: <strong><?= htmlspecialchars($userPlan['plan_status'] ?? 'free') ?></strong></div>
                </div>

                <div class="row field">
                    <span class="label">Novo plano:</span>
                    <select name="plan_id" class="select">
                        <option value="">Nenhum (Free)</option>
                        <?php if (!empty($plans)): ?>
                            <?php foreach ($plans as $p): ?>
                                <option value="<?= (int)$p['id'] ?>" <?= !empty($userPlan['plan_id']) && (int)$userPlan['plan_id'] === (int)$p['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($p['name']) ?> (<?= htmlspecialchars($p['slug']) ?>)
                                </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                </div>

                <div class="row field">
                    <span class="label">Status do plano:</span>
                    <?php $currentStatus = $userPlan['plan_status'] ?? 'free'; ?>
                    <select name="plan_status" class="select">
                        <?php
                        $statusOptions = [
                            'free'      => 'Free',
                            'pending'   => 'Pending',
                            'active'    => 'Active',
                            'past_due'  => 'Past due',
                            'canceled'  => 'Canceled',
                        ];
                        foreach ($statusOptions as $value => $label): ?>
                            <option value="<?= $value ?>" <?= $currentStatus === $value ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="row field">
                    <span class="label">Data de expiração (opcional):</span>
                    <?php
                    $expiresValue = '';
                    if (!empty($userPlan['plan_expires_at'])) {
                        $expiresValue = substr($userPlan['plan_expires_at'], 0, 10);
                    }
                    ?>
                    <input type="date" name="plan_expires_at" class="input" value="<?= htmlspecialchars($expiresValue) ?>">
                </div>

                <div class="checkbox-row">
                    <input type="checkbox" id="remove_plan" name="remove_plan" value="1">
                    <label for="remove_plan">Remover plano e voltar para Free (limpar assinatura e expiração)</label>
                </div>

                <div class="actions">
                    <button type="submit" class="btn-primary">Salvar alterações</button>
                    <a href="<?= BASE_URL ?>?c=admin&a=index" class="btn-secondary">Voltar ao painel</a>
                </div>
            </form>
        </div>
    </main>
</div>
</body>
</html>
