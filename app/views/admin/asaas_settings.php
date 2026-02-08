<?php
http_response_code(404);
echo 'Not found';
exit;
?>
<head>
    <meta charset="UTF-8">
    <title>EasyChart - Configurações Asaas</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body{margin:0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;background:#f3f4f6;color:#111827;}
        .page{min-height:100vh;display:flex;}
        .content{flex:1;padding:24px 32px;max-width:720px;margin:0 auto;}
        h1{font-size:24px;margin:0 0 4px;}
        .subtitle{color:#6b7280;font-size:14px;margin-bottom:20px;}
        .card{background:#ffffff;border-radius:16px;box-shadow:0 20px 40px rgba(15,23,42,0.06);padding:24px 24px 20px;margin-bottom:24px;}
        label{display:block;font-size:13px;font-weight:500;color:#374151;margin-bottom:6px;}
        .input{width:100%;padding:9px 11px;border-radius:10px;border:1px solid #e5e7eb;font-size:14px;outline:none;margin-bottom:14px;}
        .input:focus{border-color:#2563eb;box-shadow:0 0 0 1px rgba(37,99,235,.15);}
        .select{width:100%;padding:9px 11px;border-radius:10px;border:1px solid #e5e7eb;font-size:14px;outline:none;margin-bottom:14px;background:#ffffff;}
        .btn-primary{border:none;border-radius:10px;padding:10px 18px;background:#2563eb;color:#ffffff;font-weight:600;font-size:14px;cursor:pointer;}
        .btn-primary:hover{background:#1d4ed8;}
        .alert-error{margin-bottom:12px;font-size:13px;color:#b91c1c;background:#fee2e2;border-radius:8px;padding:8px 10px;}
        .alert-success{margin-bottom:12px;font-size:13px;color:#166534;background:#dcfce7;border-radius:8px;padding:8px 10px;}
        .back-link{display:inline-block;margin-bottom:16px;font-size:13px;color:#2563eb;text-decoration:none;}
        .back-link:hover{text-decoration:underline;}
        .help-text{font-size:12px;color:#6b7280;margin-top:-8px;margin-bottom:12px;}
    </style>
</head>
<body>
<div class="page">
    <div class="content">
        <a class="back-link" href="<?= BASE_URL ?>?c=admin&a=index">&larr; Voltar para o painel de administração</a>
        <h1>Integração Asaas</h1>
        <p class="subtitle">Configure o ambiente e as chaves de API utilizadas para criar cobranças do plano Premium.</p>

        <div class="card">
            <?php if (!empty($error)): ?>
                <div class="alert-error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <?php if (!empty($success)): ?>
                <div class="alert-success">Configurações Asaas salvas com sucesso.</div>
            <?php endif; ?>

            <form method="post">
                <label>Ambiente ativo</label>
                <select class="select" name="asaas_env">
                    <option value="sandbox" <?= ($settings['asaas_env'] ?? 'sandbox') === 'sandbox' ? 'selected' : '' ?>>Sandbox</option>
                    <option value="production" <?= ($settings['asaas_env'] ?? 'sandbox') === 'production' ? 'selected' : '' ?>>Produção</option>
                </select>
                <div class="help-text">Escolha se o sistema deve usar o ambiente de testes (Sandbox) ou o ambiente real de cobranças (Produção).</div>

                <label>API Key - Sandbox</label>
                <input class="input" name="asaas_sandbox_key" type="text" value="<?= htmlspecialchars($settings['asaas_sandbox_key'] ?? '') ?>">
                <div class="help-text">Chave de API do ambiente Sandbox do Asaas (recomendado para testes).</div>

                <label>API Key - Produção</label>
                <input class="input" name="asaas_production_key" type="text" value="<?= htmlspecialchars($settings['asaas_production_key'] ?? '') ?>">
                <div class="help-text">Chave de API do ambiente de Produção do Asaas (use apenas quando o fluxo já estiver validado).</div>

                <button class="btn-primary" type="submit">Salvar configurações</button>
            </form>
        </div>
    </div>
</div>
</body>
</html>
