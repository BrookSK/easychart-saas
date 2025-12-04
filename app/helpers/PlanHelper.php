<?php

require_once __DIR__ . '/../core/Database.php';

class PlanHelper
{
    public static function getCurrentPlan(PDO $pdo, int $userId): array
    {
        // Busca usuário com plano associado
        $stmt = $pdo->prepare('SELECT u.plan_id, u.plan_status, p.* FROM users u LEFT JOIN plans p ON u.plan_id = p.id WHERE u.id = :id LIMIT 1');
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch();

        // Se não tiver plano associado, tenta carregar plano free por slug
        if (!$row || !$row['plan_id']) {
            $planStmt = $pdo->prepare("SELECT * FROM plans WHERE slug = 'free' LIMIT 1");
            $planStmt->execute();
            $plan = $planStmt->fetch() ?: null;

            return [
                'plan_status' => 'free',
                'plan'        => $plan,
            ];
        }

        return [
            'plan_status' => $row['plan_status'] ?? 'free',
            'plan'        => $row,
        ];
    }

    public static function canUploadSpreadsheet(PDO $pdo, int $userId): array
    {
        $info  = self::getCurrentPlan($pdo, $userId);
        $plan  = $info['plan'];
        $status = $info['plan_status'];

        // Se não houver plano configurado, considera free
        $spreadsheetLimit = $plan['monthly_spreadsheet_limit'] ?? 1;

        // Plano premium ativo: sem limite
        if ($status === 'active' && isset($plan['slug']) && $plan['slug'] === 'premium') {
            return [true, null];
        }

        // Conta uploads deste mês
        $startOfMonth = date('Y-m-01 00:00:00');
        $endOfMonth   = date('Y-m-t 23:59:59');

        $stmt = $pdo->prepare('SELECT COUNT(*) AS c FROM spreadsheets WHERE user_id = :uid AND created_at BETWEEN :start AND :end');
        $stmt->execute([
            'uid'   => $userId,
            'start' => $startOfMonth,
            'end'   => $endOfMonth,
        ]);
        $count = (int)$stmt->fetch()['c'];

        if ($spreadsheetLimit !== null && $count >= (int)$spreadsheetLimit) {
            return [false, 'No plano Free você pode enviar apenas 1 planilha por mês. '
                . 'Assine o Premium para ter uploads ilimitados. '
                . '<a href="' . BASE_URL . '?c=asaas&a=showCheckout">Clique aqui para assinar</a>.'];
        }

        return [true, null];
    }

    public static function canGenerateCharts(PDO $pdo, int $userId, int $newChartsCount = 1): array
    {
        $info  = self::getCurrentPlan($pdo, $userId);
        $plan  = $info['plan'];
        $status = $info['plan_status'];

        $chartLimit = $plan['monthly_chart_limit'] ?? 1;
        $isPremium = ($status === 'active' && isset($plan['slug']) && $plan['slug'] === 'premium');

        // Plano premium ativo com limite NULL (ilimitado): libera
        if ($isPremium && $chartLimit === null) {
            return [true, null];
        }

        $startOfMonth = date('Y-m-01 00:00:00');
        $endOfMonth   = date('Y-m-t 23:59:59');

        $stmt = $pdo->prepare('SELECT COUNT(*) AS c FROM charts WHERE user_id = :uid AND created_at BETWEEN :start AND :end');
        $stmt->execute([
            'uid'   => $userId,
            'start' => $startOfMonth,
            'end'   => $endOfMonth,
        ]);
        $count = (int)$stmt->fetch()['c'];

        // Verifica se atingiu o limite
        $reachedLimit = ($chartLimit !== null && ($count >= (int)$chartLimit || ($count + $newChartsCount) > (int)$chartLimit));

        if ($reachedLimit) {
            // Se era premium com limite configurado e atingiu, rebaixa para free automaticamente
            if ($isPremium) {
                self::downgradeToFree($pdo, $userId);

                return [false, 'Seus <strong>tokens de geração de gráficos</strong> acabaram neste mês. '
                    . 'Sua conta foi automaticamente convertida para o plano Free. '
                    . 'Para continuar gerando gráficos com IA sem limitação, contrate novamente o plano Premium. '
                    . '<a href="' . BASE_URL . '?c=asaas&a=showCheckout" style="color:#2563eb;font-weight:600;">Clique aqui para assinar o Premium</a>.'];
            }

            // Plano Free atingiu limite
            return [false, 'Você chegou ao limite de geração de gráficos do seu plano atual (Free): '
                . (int)$chartLimit . ' gráfico(s) por mês. '
                . 'Para continuar gerando gráficos com IA, faça upgrade para o plano Premium. '
                . '<a href="' . BASE_URL . '?c=asaas&a=showCheckout" style="color:#2563eb;font-weight:600;">Clique aqui para assinar o Premium</a>.'];
        }

        return [true, null];
    }

    /**
     * Rebaixa o usuário para o plano Free.
     */
    public static function downgradeToFree(PDO $pdo, int $userId): void
    {
        // Busca o ID do plano free
        $planStmt = $pdo->prepare("SELECT id FROM plans WHERE slug = 'free' LIMIT 1");
        $planStmt->execute();
        $freePlan = $planStmt->fetch();

        $freePlanId = $freePlan ? (int)$freePlan['id'] : null;

        // Atualiza o usuário para o plano free
        $stmt = $pdo->prepare('UPDATE users SET plan_id = :plan_id, plan_status = :status, plan_expires_at = NULL WHERE id = :uid');
        $stmt->execute([
            'plan_id' => $freePlanId,
            'status'  => 'free',
            'uid'     => $userId,
        ]);

        // Atualiza a sessão se o usuário atual for o mesmo
        if (isset($_SESSION['user']['id']) && (int)$_SESSION['user']['id'] === $userId) {
            $_SESSION['user']['plan_id'] = $freePlanId;
            $_SESSION['user']['plan_status'] = 'free';
        }
    }
}
