<?php
declare(strict_types=1);

function tcSecurityHardenSessionSettings(): void
{
  if (session_status() !== PHP_SESSION_NONE) {
    return;
  }
  $secure = (
    (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
    || (string)($_SERVER['SERVER_PORT'] ?? '') === '443'
  );
  ini_set('session.use_strict_mode', '1');
  ini_set('session.use_only_cookies', '1');
  ini_set('session.cookie_httponly', '1');
  ini_set('session.cookie_samesite', 'Lax');
  ini_set('session.cookie_secure', $secure ? '1' : '0');
}

function tcSecurityEnsureSessionStarted(): void
{
  if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
  }
}

function tcSecurityCreateCspNonce(): string
{
  return base64_encode(random_bytes(16));
}

function tcSecuritySendCommonHeaders(): void
{
  header('X-Content-Type-Options: nosniff');
  header('X-Frame-Options: DENY');
  header('Referrer-Policy: same-origin');
  header('Cross-Origin-Resource-Policy: same-origin');
  header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  header('Pragma: no-cache');
  header('Expires: 0');
}

function tcSecuritySendPageHeaders(string $nonce): void
{
  tcSecuritySendCommonHeaders();
  header(
    "Content-Security-Policy: default-src 'self'; "
    . "script-src 'self' 'nonce-{$nonce}'; "
    . "style-src 'self' 'nonce-{$nonce}'; "
    . "font-src 'self'; img-src 'self' data:; connect-src 'self'; "
    . "base-uri 'none'; form-action 'self'; frame-ancestors 'none'; object-src 'none'"
  );
}

function tcSecurityGetCsrfToken(): string
{
  tcSecurityEnsureSessionStarted();
  $current = $_SESSION['task_club_csrf'] ?? null;
  if (is_string($current) && $current !== '') {
    return $current;
  }
  $token = bin2hex(random_bytes(32));
  $_SESSION['task_club_csrf'] = $token;
  return $token;
}

function tcSecurityReadCsrfFromRequest(?array $payload = null, string $field = 'csrf'): string
{
  if (is_array($payload) && array_key_exists($field, $payload)) {
    return trim((string)$payload[$field]);
  }
  if (array_key_exists($field, $_POST)) {
    return trim((string)$_POST[$field]);
  }
  if (array_key_exists($field, $_GET)) {
    return trim((string)$_GET[$field]);
  }
  if (isset($_SERVER['HTTP_X_TC_CSRF'])) {
    return trim((string)$_SERVER['HTTP_X_TC_CSRF']);
  }
  return '';
}

function tcSecurityIsValidCsrfToken(string $providedToken): bool
{
  $provided = trim($providedToken);
  if ($provided === '') {
    return false;
  }
  $expected = tcSecurityGetCsrfToken();
  return $expected !== '' && hash_equals($expected, $provided);
}
