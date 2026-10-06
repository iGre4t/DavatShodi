<?php
// Copy to telegram.config.local.php on the server. Never commit real credentials.
return [
    'bot_token' => '',
    'webhook_secret' => '', // Random secret; the setup command validates it.
    'proxy_url' => '', // e.g. socks5h://proxy.example.com:1080 or http://proxy.example.com:8080
    'proxy_username' => '',
    'proxy_password' => '',
];
