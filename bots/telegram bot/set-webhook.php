<?php

declare(strict_types=1);

const CONFIG_FILE = __DIR__ . '/bot.json';

header('Content-Type: application/json; charset=utf-8');

if (!is_file(CONFIG_FILE)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Missing bot.json']);
    exit;
}

$config = json_decode((string) file_get_contents(CONFIG_FILE), true);
if (!is_array($config) || empty($config['bot_token']) || empty($config['webhook_url'])) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Invalid bot.json']);
    exit;
}

$url = "https://api.telegram.org/bot{$config['bot_token']}/setWebhook";
$payload = [
    'url' => (string) $config['webhook_url'],
];

if (!empty($config['webhook_secret_token'])) {
    $payload['secret_token'] = (string) $config['webhook_secret_token'];
}

$allowedUpdates = ['message', 'callback_query'];
if (!empty($config['allowed_updates']) && is_array($config['allowed_updates'])) {
    $allowedUpdates = array_values($config['allowed_updates']);
}
$payload['allowed_updates'] = json_encode($allowedUpdates);

$response = telegramPost($url, $payload);

echo $response;

function telegramPost(string $url, array $payload): string
{
    $postData = http_build_query($payload);

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postData,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
        ]);
        $result = curl_exec($ch);
        curl_close($ch);
        return is_string($result) ? $result : '{"ok":false,"description":"No response"}';
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-type: application/x-www-form-urlencoded\r\n",
            'content' => $postData,
            'timeout' => 20,
        ],
    ]);

    $result = @file_get_contents($url, false, $context);
    return is_string($result) ? $result : '{"ok":false,"description":"No response"}';
}
