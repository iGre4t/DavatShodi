<?php

$campaigns = [
    'zendegi' => '/mini%20apps/RateMe/index.php',
    'dastavard' => '/mini%20apps/Task%20Club/index.php',
];

$slug = trim((string)($_GET['campaign'] ?? ''), '/');

if ($slug !== '' && isset($campaigns[$slug])) {
    header('Location: ' . $campaigns[$slug], true, 302);
    exit;
}

http_response_code(404);
echo 'Campaign not found.';
