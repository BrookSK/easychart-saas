<?php 
require_once __DIR__ . '/../../config/config.php'; 
http_response_code(404); 
echo 'Not found';
exit;
?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <title>EasyChart - Plano Premium</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body {
            margin: 0;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background: #f3f4ff;
            color: #111827;
        }

        .layout {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        .topbar {
            height: 56px;
            background: #0f172a;
            color: #e5e7eb;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 28px;
        }

        .topbar-left {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .logo-mark {
            display: flex;
            align-items: center;
            gap: 6px;
            color: #e5e7eb;
            font-weight: 600;
        }

        .logo-icon {
            width: 24px;
            height: 24px;
            border-radius: 7px;
            background: linear-gradient(135deg, #2563eb, #4ade80);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .logo-icon-bar {
            width: 4px;
            border-radius: 3px;
            background: #ffffff;
            margin: 0 1px;
        }

        .top-nav a {
            color: #cbd5f5;
            font-size: 14px;
            margin-right: 18px;
            text-decoration: none;
        }

        .top-nav a.active {
            color: #ffffff;
            font-weight: 600;
        }

        .topbar-right {
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .topbar-right a {
            color: #e5e7eb;
            text-decoration: none;
        }

        .content {
            flex: 1;
            padding: 24px 40px 40px;
            display: flex;
            justify-content: center;
        }

        .card {
            background: #ffffff;
            border-radius: 16px;
            box-shadow: 0 18px 30px rgba(15, 23, 42, 0.08);
            padding: 24px 24px 20px;
            max-width: 720px;
            width: 100%;
        }

        .section-title {
            font-weight: 600;
            margin-bottom: 4px;
            font-size: 20px;
        }

        .section-subtitle {
            font-size: 14px;
            color: #6b7280;
            margin-bottom: 16px;
        }

        .plan-box {
            border-radius: 12px;
            border: 1px solid #e5e7eb;
            padding: 16px 18px;
            margin-bottom: 20px;
            background: #f9fafb;
        }

        .plan-name {
            font-weight: 600;
            font-size: 16px;
            margin-bottom: 4px;
        }

        .plan-price {
            font-size: 22px;
            font-weight: 700;
            color: #2563eb;
            margin-bottom: 4px;
        }

        .plan-details {
            font-size: 13px;
            color: #4b5563;
        }

        label {
            display: block;
            font-size: 13px;
            font-weight: 500;
            color: #374151;
            margin-bottom: 6px;
        }

        .input {
            width: 100%;
            border-radius: 10px;
            border: 1px solid #e5e7eb;
            padding: 9px 11px;
            font-size: 14px;
            outline: none;
            margin-bottom: 10px;
        }

        .input:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 1px rgba(37, 99, 235, 0.15);
        }

        .btn-primary {
            margin-top: 8px;
            padding: 10px 18px;
            background: #2563eb;
            border-radius: 9px;
            border: none;
            color: #ffffff;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            width: 100%;
        }

        .btn-primary:hover {
            background: #1d4ed8;
        }

        .two-cols {
            display: flex;
            gap: 8px;
        }
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
                </nav>
            </div>
            <div class="topbar-right">
                <span><?= Lang::get('Welcome') ?>, <?= isset($_SESSION['user']['full_name']) ? htmlspecialchars($_SESSION['user']['full_name']) : 'User' ?></span>
                <a href="<?= BASE_URL ?>?c=dashboard&a=logout"><?= Lang::get('Logout') ?></a>
            </div>
        </header>

        <main class="content">
            <div class="card">
                <div class="section-title">Plano Premium</div>
                <div class="section-subtitle">
                    Revise os detalhes do plano antes de confirmar o pagamento.
                </div>

                <div class="plan-box">
                    <div class="plan-name"><?= htmlspecialchars($premiumPlan['name'] ?? 'Premium') ?></div>
                    <div class="plan-price">
                        R$ <?= isset($premiumPlan['price_cents']) ? number_format($premiumPlan['price_cents'] / 100, 2, ',', '.') : '29,90' ?>/mês
                    </div>
                    <div class="plan-details">
                        - Uploads ilimitados de planilhas<br>
                        - Geração ilimitada de gráficos com IA<br>
                        - Suporte prioritário
                    </div>
                </div>

                <form method="post" action="<?= BASE_URL ?>?c=asaas&a=subscribePremium">
                    <div class="section-title" style="font-size:16px;margin-top:4px;">Seus dados</div>
                    <div class="section-subtitle" style="margin-bottom:10px;">Essas informações serão usadas para cobrança e emissão da assinatura.</div>

                    <label>Nome completo</label>
                    <input class="input" name="full_name" value="<?= htmlspecialchars($userRow['full_name'] ?? '') ?>" required>

                    <label>CPF</label>
                    <input class="input" name="cpf" value="<?= htmlspecialchars($userRow['cpf'] ?? '') ?>" placeholder="000.000.000-00" required>

                    <label>Telefone</label>
                    <input class="input" name="phone" value="<?= htmlspecialchars($userRow['phone'] ?? '') ?>" placeholder="(00) 00000-0000" required>

                    <label>CEP do titular</label>
                    <input class="input" name="card_postal_code" placeholder="00000-000" required>

                    <label>Rua</label>
                    <input class="input" name="card_address_number" placeholder="Nome da rua e número" required>

                    <div class="section-title" style="font-size:16px;margin-top:10px;">Dados do cartão</div>

                    <label>Nome impresso no cartão</label>
                    <input class="input" name="card_holder_name" placeholder="Como aparece no cartão" required>

                    <label>Número do cartão</label>
                    <input class="input" name="card_number" placeholder="0000 0000 0000 0000" required>

                    <label>Validade (mês/ano)</label>
                    <div class="two-cols">
                        <input class="input" name="card_exp_month" placeholder="MM" style="max-width:80px;" required>
                        <input class="input" name="card_exp_year" placeholder="AAAA" style="max-width:120px;" required>
                    </div>

                    <label>CVV</label>
                    <input class="input" name="card_cvv" placeholder="CVV" style="max-width:120px;" required>

                    <button class="btn-primary" type="submit">Assinar Premium</button>
                </form>
            </div>
        </main>
    </div>
</body>

</html>