<?php
http_response_code(404);
echo 'Not found';
exit;
?>
<head>
    <meta charset="UTF-8">
    <title>EasyChart - <?= isset($plan['id']) ? 'Editar plano' : 'Novo plano' ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body{margin:0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;background:#f3f4ff;color:#111827;}
        .layout{min-height:100vh;display:flex;flex-direction:column;}
        .content{flex:1;padding:24px 40px 40px;max-width:720px;}
        .page-title{font-size:24px;font-weight:600;margin-bottom:4px;}
        .page-subtitle{font-size:14px;color:#6b7280;margin-bottom:16px;}
        label{display:block;font-size:13px;font-weight:500;color:#374151;margin-bottom:6px;}
        .input{width:100%;padding:9px 11px;border-radius:10px;border:1px solid #e5e7eb;font-size:14px;outline:none;margin-bottom:14px;}
        .input:focus{border-color:#2563eb;box-shadow:0 0 0 1px rgba(37,99,235,.15);}
        .checkbox-row{display:flex;align-items:center;gap:8px;margin:8px 0 16px;font-size:13px;color:#374151;}
        .btn-primary{border:none;border-radius:10px;padding:10px 18px;background:#2563eb;color:#ffffff;font-weight:600;font-size:14px;cursor:pointer;}
        .btn-primary:hover{background:#1d4ed8;}
        .alert-error{margin-bottom:12px;font-size:13px;color:#b91c1c;background:#fee2e2;border-radius:8px;padding:8px 10px;}
        .alert-success{margin-bottom:12px;font-size:13px;color:#166534;background:#dcfce7;border-radius:8px;padding:8px 10px;}
        .back-link{display:inline-block;margin-bottom:16px;font-size:13px;color:#2563eb;text-decoration:none;}
        .back-link:hover{text-decoration:underline;}
    </style>
</head>
<body>
<div class="layout">
    <main class="content">
        <a class="back-link" href="<?= BASE_URL ?>?c=admin&a=plans">&larr; Voltar para planos</a>
        <div class="page-title"><?= isset($plan['id']) ? 'Editar plano' : 'Novo plano' ?></div>
        <div class="page-subtitle">Defina nome, slug, preço e limites mensais do plano.</div>

        <?php if (!empty($error)): ?>
            <div class="alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if (!empty($success)): ?>
            <div class="alert-success">Plano salvo com sucesso.</div>
        <?php endif; ?>

        <form method="post">
            <label>Nome do plano</label>
            <input class="input" name="name" value="<?= htmlspecialchars($plan['name'] ?? '') ?>" required>

            <label>Slug (identificador interno)</label>
            <input class="input" name="slug" value="<?= htmlspecialchars($plan['slug'] ?? '') ?>" required>

            <label>Preço (em centavos)</label>
            <input class="input" name="price_cents" type="number" min="0" step="1" value="<?= htmlspecialchars($plan['price_cents'] ?? 0) ?>" required>

            <label>Uploads de planilhas por mês (deixe em branco para ilimitado)</label>
            <input class="input" name="monthly_spreadsheet_limit" type="number" min="0" step="1" value="<?= $plan['monthly_spreadsheet_limit'] !== null ? (int)$plan['monthly_spreadsheet_limit'] : '' ?>">

            <label>Gráficos gerados por mês (deixe em branco para ilimitado)</label>
            <input class="input" name="monthly_chart_limit" type="number" min="0" step="1" value="<?= $plan['monthly_chart_limit'] !== null ? (int)$plan['monthly_chart_limit'] : '' ?>">

            <label>Tokens por mês (deixe em branco para ilimitado)</label>
            <input class="input" name="monthly_token_limit" type="number" min="0" step="1" value="<?= $plan['monthly_token_limit'] !== null ? (int)$plan['monthly_token_limit'] : '' ?>">

            <div class="checkbox-row">
                <input type="checkbox" id="is_active" name="is_active" value="1" <?= !empty($plan['is_active']) ? 'checked' : '' ?>>
                <label for="is_active" style="margin:0;">Plano ativo e disponível para os usuários</label>
            </div>

            <button class="btn-primary" type="submit">Salvar plano</button>
        </form>
    </main>
</div>
</body>
</html>
