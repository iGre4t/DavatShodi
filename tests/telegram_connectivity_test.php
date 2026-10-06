<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/api/lib/system-telegram.php';
function connectivityAssert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
connectivityAssert(systemTelegramProxyOptions([])[CURLOPT_PROXY] === '', 'Direct check must disable environment proxy');
foreach (['http', 'https', 'socks5', 'socks5h'] as $scheme) {
    $options = systemTelegramProxyOptions(['proxy_url' => $scheme . '://proxy.example:1080', 'proxy_username' => 'test-user', 'proxy_password' => 'test-pass']);
    connectivityAssert($options[CURLOPT_PROXY] === $scheme . '://proxy.example:1080' && $options[CURLOPT_NOPROXY] === '', 'Proxy route must not bypass configured proxy');
    connectivityAssert($options[CURLOPT_PROXYUSERNAME] === 'test-user' && $options[CURLOPT_PROXYPASSWORD] === 'test-pass', 'Proxy credentials missing');
}
foreach (['mtproto://proxy.example:443','http://proxy.example','http://user:pass@proxy.example:80','http://proxy.example:80/path','http://proxy.example:80?secret=yes','file:///tmp/proxy','http://proxy.example:99999'] as $url) {
    try { systemTelegramProxyOptions(['proxy_url' => $url]); throw new RuntimeException('Unsafe proxy accepted'); }
    catch (InvalidArgumentException $expected) {}
}
$fixture = ['errno' => 0, 'http_status' => 200, 'elapsed_ms' => 12, 'data' => ['ok' => true, 'result' => ['username' => 'test_bot']]];
connectivityAssert(systemTelegramDiagnoseResult($fixture)['ok'], 'Valid getMe was rejected');
$fixture['data'] = ['ok' => false, 'error_code' => 401]; $fixture['http_status'] = 401;
connectivityAssert(systemTelegramDiagnoseResult($fixture)['kind'] === 'token', 'Invalid token confused with network failure');
$fixture['errno'] = 28; $fixture['http_status'] = 0; $fixture['data'] = null;
connectivityAssert(systemTelegramDiagnoseResult($fixture)['kind'] === 'network', 'Timeout not identified');
$fixture['errno'] = 0; $fixture['http_status'] = 403;
connectivityAssert(systemTelegramDiagnoseResult($fixture)['kind'] === 'unexpected_response', 'HTML block page not identified');
$config = ['bot_token'=>'12345:FAKE-TOKEN', 'webhook_secret'=>'fake-secret', 'proxy_url'=>'socks5h://proxy.example:1080', 'proxy_username'=>'fake-user', 'proxy_password'=>'fake-pass'];
$redacted = systemTelegramRedact(implode(' ', $config) . ' https://api.telegram.org/bot98765:OTHER-TOKEN/getMe', $config);
foreach ($config as $secret) connectivityAssert(!str_contains($redacted, $secret), 'Secret exposed in diagnostics');
connectivityAssert(!str_contains($redacted, 'OTHER-TOKEN'), 'Bot URL token exposed');
echo "Telegram proxy validation, connection classification and secret redaction passed.\n";
