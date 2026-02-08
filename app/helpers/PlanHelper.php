<?php

require_once __DIR__ . '/../core/Database.php';

class PlanHelper
{
    public static function getCurrentPlan(PDO $pdo, int $userId): array
    {
        return [
            'plan_status' => 'active',
            'plan'        => null,
        ];
    }

    public static function canConsumeTokens(PDO $pdo, int $userId, int $tokensToConsume): array
    {
        return [true, null, null, null];
    }

    public static function addTokenUsage(PDO $pdo, int $userId, int $tokensUsed): void
    {
        return;
    }

    public static function canUploadSpreadsheet(PDO $pdo, int $userId): array
    {
        return [true, null];
    }

    public static function canGenerateCharts(PDO $pdo, int $userId, int $newChartsCount = 1): array
    {
        return [true, null];
    }
}
