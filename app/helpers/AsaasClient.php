<?php

require_once __DIR__ . '/../core/Database.php';

class AsaasClient
{
    private static function getConfig(PDO $pdo): array
    {
        // Busca configurações Asaas salvas no painel admin
        $stmt = $pdo->prepare("SELECT provider, api_key FROM api_configs WHERE provider IN ('asaas_sandbox','asaas_production','asaas_env')");
        $stmt->execute();
        $rows = $stmt->fetchAll();

        $env = 'sandbox';
        $sandboxKey = '';
        $productionKey = '';
        foreach ($rows as $row) {
            if ($row['provider'] === 'asaas_env') {
                $env = $row['api_key'] ?: 'sandbox';
            } elseif ($row['provider'] === 'asaas_sandbox') {
                $sandboxKey = $row['api_key'];
            } elseif ($row['provider'] === 'asaas_production') {
                $productionKey = $row['api_key'];
            }
        }

        $env = $env === 'production' ? 'production' : 'sandbox';

        if ($env === 'production') {
            $baseUrl = 'https://api.asaas.com/api/v3';
            $apiKey  = $productionKey;

            if (!$apiKey) {
                throw new RuntimeException('A API key de produção do Asaas não está configurada.');
            }
        } else {
            $baseUrl = 'https://sandbox.asaas.com/api/v3';
            $apiKey  = $sandboxKey;

            if (!$apiKey) {
                throw new RuntimeException('A API key de sandbox do Asaas não está configurada.');
            }
        }

        return [
            'base_url' => $baseUrl,
            'api_key'  => $apiKey,
        ];
    }

    private static function request(string $method, string $url, string $apiKey, ?array $body = null): array
    {
        $ch = curl_init($url);
        $headers = [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ];

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
        ];

        $method = strtoupper($method);
        if ($method === 'POST') {
            $options[CURLOPT_POST] = true;
        } elseif ($method !== 'GET') {
            $options[CURLOPT_CUSTOMREQUEST] = $method;
        }

        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = json_encode($body);
        }

        curl_setopt_array($ch, $options);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            throw new RuntimeException('Erro de comunicação com Asaas: ' . $curlErr);
        }

        $decoded = json_decode($response, true);

        return [
            'status'   => $httpCode,
            'body_raw' => $response,
            'body'     => $decoded,
        ];
    }

    public static function createOrUpdateCustomer(PDO $pdo, array $userRow, string $cpf, string $phone): array
    {
        $config = self::getConfig($pdo);
        $baseUrl = $config['base_url'];
        $apiKey  = $config['api_key'];

        $payload = [
            'name'        => $userRow['full_name'],
            'email'       => $userRow['email'],
            'cpfCnpj'     => $cpf,
            'mobilePhone' => $phone,
        ];

        $method = 'POST';
        $url    = $baseUrl . '/customers';

        if (!empty($userRow['asaas_customer_id'])) {
            $method = 'PUT';
            $url    = $baseUrl . '/customers/' . urlencode($userRow['asaas_customer_id']);
        }

        $resp = self::request($method, $url, $apiKey, $payload);
        if ($resp['status'] < 200 || $resp['status'] >= 300) {
            $msg = $resp['body']['errors'][0]['description'] ?? 'Erro ao criar/atualizar cliente no Asaas.';
            throw new RuntimeException($msg);
        }

        return $resp['body'];
    }

    public static function createSubscription(PDO $pdo, string $customerId, array $holderInfo, array $cardData, int $priceCents): array
    {
        $config = self::getConfig($pdo);
        $baseUrl = $config['base_url'];
        $apiKey  = $config['api_key'];

        $payload = [
            'customer'    => $customerId,
            'billingType' => 'CREDIT_CARD',
            'value'       => $priceCents / 100,
            'cycle'       => 'MONTHLY',
            'description' => 'Plano Premium EasyChart',
            'creditCard'  => [
                'holderName' => $cardData['holder_name'],
                'number'     => $cardData['number'],
                'expiryMonth'=> $cardData['exp_month'],
                'expiryYear' => $cardData['exp_year'],
                'ccv'        => $cardData['cvv'],
            ],
            'creditCardHolderInfo' => [
                'name'        => $holderInfo['name'],
                'email'       => $holderInfo['email'],
                'cpfCnpj'     => $holderInfo['cpf'],
                'mobilePhone' => $holderInfo['phone'],
            ],
        ];

        $resp = self::request('POST', $baseUrl . '/subscriptions', $apiKey, $payload);
        if ($resp['status'] < 200 || $resp['status'] >= 300) {
            $msg = $resp['body']['errors'][0]['description'] ?? 'Erro ao criar assinatura no Asaas.';
            throw new RuntimeException($msg);
        }

        return $resp['body'];
    }

    public static function cancelSubscription(PDO $pdo, string $subscriptionId): array
    {
        $config = self::getConfig($pdo);
        $baseUrl = $config['base_url'];
        $apiKey  = $config['api_key'];

        $resp = self::request('DELETE', $baseUrl . '/subscriptions/' . urlencode($subscriptionId), $apiKey, null);
        return $resp['body'] ?? [];
    }
}
