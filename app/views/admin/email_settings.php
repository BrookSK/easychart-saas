<?php require_once __DIR__ . '/../../config/config.php'; ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>EasyChart - Configurações de E-mail</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body{margin:0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;background:#f3f4f6;color:#111827;}
        .page{min-height:100vh;display:flex;}
        .content{flex:1;padding:24px 32px;max-width:960px;margin:0 auto;}
        h1{font-size:24px;margin:0 0 4px;}
        .subtitle{color:#6b7280;font-size:14px;margin-bottom:20px;}
        .card{background:#ffffff;border-radius:16px;box-shadow:0 20px 40px rgba(15,23,42,0.06);padding:24px 24px 20px;margin-bottom:24px;}
        label{display:block;font-size:13px;font-weight:500;color:#374151;margin-bottom:6px;}
        .input{width:100%;padding:9px 11px;border-radius:10px;border:1px solid #e5e7eb;font-size:14px;outline:none;margin-bottom:14px;}
        .input:focus{border-color:#2563eb;box-shadow:0 0 0 1px rgba(37,99,235,.15);}
        .grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;}
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
<div class="page">
    <div class="content">
        <a class="back-link" href="<?= BASE_URL ?>?c=admin&a=index">&larr; Voltar para o painel admin</a>
        <h1>Configurações de E-mail</h1>
        <p class="subtitle">Defina como o sistema enviará e-mails (ex.: redefinição de senha).</p>

        <div class="card">
            <?php if (!empty($error)): ?>
                <div class="alert-error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <?php if (!empty($success)): ?>
                <div class="alert-success">Configurações salvas com sucesso.</div>
            <?php endif; ?>

            <form method="post">
                <div class="grid">
                    <div>
                        <label>Remetente (e-mail)</label>
                        <input class="input" name="from_email" type="email" value="<?= htmlspecialchars($settings['from_email'] ?? '') ?>" required>
                    </div>
                    <div>
                        <label>Nome do remetente</label>
                        <input class="input" name="from_name" type="text" value="<?= htmlspecialchars($settings['from_name'] ?? '') ?>" required>
                    </div>
                </div>

                <div class="grid">
                    <div>
                        <label>Servidor SMTP</label>
                        <input class="input" name="smtp_host" type="text" value="<?= htmlspecialchars($settings['smtp_host'] ?? '') ?>">
                    </div>
                    <div>
                        <label>Porta SMTP</label>
                        <input class="input" name="smtp_port" type="number" value="<?= htmlspecialchars($settings['smtp_port'] ?? 587) ?>">
                    </div>
                </div>

                <div class="grid">
                    <div>
                        <label>Usuário SMTP</label>
                        <input class="input" name="smtp_user" type="text" value="<?= htmlspecialchars($settings['smtp_user'] ?? '') ?>">
                    </div>
                    <div>
                        <label>Senha SMTP</label>
                        <input class="input" name="smtp_password" type="password" value="<?= htmlspecialchars($settings['smtp_password'] ?? '') ?>">
                    </div>
                </div>

                <div class="checkbox-row">
                    <input type="checkbox" id="use_smtp" name="use_smtp" value="1" <?= !empty($settings['use_smtp']) ? 'checked' : '' ?>>
                    <label for="use_smtp" style="margin:0;">Usar SMTP em vez de mail() padrão do PHP</label>
                </div>

                <button class="btn-primary" type="submit">Salvar configurações</button>
            </form>
        </div>
    </div>
</div>
</body>
</html>
