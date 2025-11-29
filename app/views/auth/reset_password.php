<?php require_once __DIR__ . '/../../config/config.php'; ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>EasyChart - Definir nova senha</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body{margin:0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;background:linear-gradient(135deg,#f5f9ff,#ffffff);color:#111827;}
        .page{min-height:100vh;display:flex;align-items:center;justify-content:center;}
        .card{background:#ffffff;box-shadow:0 20px 40px rgba(15,23,42,0.08);border-radius:18px;padding:40px 40px 32px;width:100%;max-width:420px;}
        h1{text-align:center;margin:0 0 6px;font-size:24px;}
        .subtitle{text-align:center;color:#6b7280;font-size:14px;margin-bottom:24px;}
        label{display:block;font-size:13px;font-weight:500;color:#374151;margin-bottom:6px;}
        .input-wrapper{position:relative;margin-bottom:18px;}
        .input-wrapper input{width:90%;padding:11px 12px 11px 38px;border-radius:10px;border:1px solid #e5e7eb;font-size:14px;outline:none;}
        .input-wrapper input:focus{border-color:#2563eb;box-shadow:0 0 0 1px rgba(37,99,235,.15);}
        .input-icon{position:absolute;left:12px;top:50%;transform:translateY(-50%);font-size:14px;color:#9ca3af;}
        .btn-primary{width:100%;border:none;border-radius:10px;padding:11px 0;background:#2563eb;color:#ffffff;font-weight:600;font-size:15px;cursor:pointer;margin-top:4px;}
        .btn-primary:hover{background:#1d4ed8;}
        .error{margin-bottom:10px;font-size:13px;color:#b91c1c;background:#fee2e2;border-radius:8px;padding:6px 8px;}
        .success{margin-bottom:10px;font-size:13px;color:#166534;background:#dcfce7;border-radius:8px;padding:6px 8px;}
        .muted-link{text-align:center;margin:16px 0 0;font-size:13px;color:#6b7280;}
        .muted-link a{color:#2563eb;font-weight:500;text-decoration:none;}
        .muted-link a:hover{text-decoration:underline;}
    </style>
</head>
<body>
<div class="page">
    <div class="card">
        <h1>Nova senha</h1>
        <p class="subtitle">Defina uma nova senha para sua conta.</p>

        <?php if (!empty($error)): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if (!empty($success)): ?>
            <div class="success">
                Senha redefinida com sucesso. Você já pode fazer login.
            </div>
        <?php endif; ?>

        <?php if (empty($success)): ?>
            <form method="post">
                <input type="hidden" name="token" value="<?= htmlspecialchars($token ?? '') ?>">

                <label>Nova senha</label>
                <div class="input-wrapper">
                    <span class="input-icon">&#128274;</span>
                    <input type="password" name="new_password" placeholder="Nova senha" required>
                </div>

                <label>Confirmar nova senha</label>
                <div class="input-wrapper">
                    <span class="input-icon">&#128274;</span>
                    <input type="password" name="confirm_password" placeholder="Confirme a nova senha" required>
                </div>

                <button class="btn-primary" type="submit">Salvar nova senha</button>
            </form>
        <?php endif; ?>

        <p class="muted-link">
            <a href="<?= BASE_URL ?>?c=auth&a=login">Voltar para o login</a>
        </p>
    </div>
</div>
</body>
</html>
