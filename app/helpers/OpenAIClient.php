<?php

class OpenAIClient
{
    private static function request(string $apiKey, string $model, array $messages, float $temperature = 0.2): array
    {
        $url = 'https://api.openai.com/v1/chat/completions';

        $payload = [
            'model' => $model,
            'messages' => $messages,
            'temperature' => $temperature,
        ];

        $ch = curl_init($url);
        $headers = [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ];

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);

        $resp = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err) {
            return ['ok' => false, 'status' => 0, 'error' => $err, 'raw' => null, 'json' => null];
        }

        $json = json_decode((string)$resp, true);

        if ($http < 200 || $http >= 300) {
            $msg = $json['error']['message'] ?? ('HTTP ' . $http);
            return ['ok' => false, 'status' => $http, 'error' => $msg, 'raw' => $resp, 'json' => $json];
        }

        return ['ok' => true, 'status' => $http, 'error' => null, 'raw' => $resp, 'json' => $json];
    }

    public static function jsonCall(string $apiKey, string $model, string $system, string $user, float $temperature = 0.2): array
    {
        $messages = [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ];

        $resp = self::request($apiKey, $model, $messages, $temperature);
        if (empty($resp['ok'])) {
            return ['ok' => false, 'error' => $resp['error'] ?? 'OpenAI request failed', 'data' => null];
        }

        $content = $resp['json']['choices'][0]['message']['content'] ?? '';
        $content = trim((string)$content);

        // tenta extrair JSON mesmo se vier com texto extra
        $jsonStr = $content;
        $start = strpos($jsonStr, '{');
        $end = strrpos($jsonStr, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $jsonStr = substr($jsonStr, $start, $end - $start + 1);
        }

        $data = json_decode($jsonStr, true);
        if (!is_array($data)) {
            return ['ok' => false, 'error' => 'Invalid JSON from OpenAI', 'data' => null];
        }

        return ['ok' => true, 'error' => null, 'data' => $data];
    }
}
