<?php

declare(strict_types=1);

const CONFIG_FILE = __DIR__ . '/bot.json';

header('Content-Type: text/plain; charset=utf-8');

if (!is_file(CONFIG_FILE)) {
    http_response_code(500);
    echo "Missing bot.json configuration file.";
    exit;
}

$config = json_decode((string) file_get_contents(CONFIG_FILE), true);
if (!is_array($config) || empty($config['bot_token'])) {
    http_response_code(500);
    echo "Invalid bot.json configuration.";
    exit;
}

if (!empty($config['webhook_secret_token'])) {
    $incomingSecret = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
    if (!hash_equals((string) $config['webhook_secret_token'], (string) $incomingSecret)) {
        http_response_code(403);
        echo "Forbidden";
        exit;
    }
}

$rawInput = (string) file_get_contents('php://input');
$update = json_decode($rawInput, true);

if (!is_array($update)) {
    http_response_code(200);
    echo "No update";
    exit;
}

$message = $update['message'] ?? null;
if (!is_array($message)) {
    http_response_code(200);
    echo "Ignored";
    exit;
}

$chatId = $message['chat']['id'] ?? null;
$userId = $message['from']['id'] ?? null;

if ($chatId === null || $userId === null) {
    http_response_code(200);
    echo "Ignored";
    exit;
}

$text = "Your Telegram ID is: {$userId}";
sendMessage((string) $config['bot_token'], (string) $chatId, $text);

http_response_code(200);
echo "OK";

function sendMessage(string $token, string $chatId, string $text): void
{
    $url = "https://api.telegram.org/bot{$token}/sendMessage";
    $postData = http_build_query([
        'chat_id' => $chatId,
        'text' => $text,
    ]);

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postData,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        curl_exec($ch);
        curl_close($ch);
        return;
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-type: application/x-www-form-urlencoded\r\n",
            'content' => $postData,
            'timeout' => 10,
        ],
    ]);

    @file_get_contents($url, false, $context);
}
