<?php
declare(strict_types=1);


require_once __DIR__ . '/egm-database-runtime.php';
require_once dirname(__DIR__, 2) . '/api/lib/common.php';
require_once dirname(__DIR__, 2) . '/api/lib/egm-instance-storage.php';

function egmSecurityRequireDatabaseRuntime(): void
{
  $context = egmDatabaseRuntimeContextForPath(__DIR__ . '/Setting.json');
  if (!is_array($context)) {
    throw new RuntimeException('Event Guest Manager requires its registered database instance.');
  }
  egmInstanceWriteData($context['pdo'], $context['code'], 'storage_mode', [
    'mode' => 'database_only',
    'version' => 1,
  ]);
}

egmSecurityRequireDatabaseRuntime();

function egmSecurityHardenSessionSettings(): void
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

function egmSecurityEnsureSessionStarted(): void
{
  if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
  }
}

function egmSecurityCreateCspNonce(): string
{
  return base64_encode(random_bytes(16));
}

function egmSecuritySendCommonHeaders(): void
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

function egmSecuritySendPageHeaders(string $nonce): void
{
  egmSecuritySendCommonHeaders();
  header(
    "Content-Security-Policy: default-src 'self'; "
    . "script-src 'self' 'nonce-{$nonce}'; "
    . "style-src 'self' 'nonce-{$nonce}'; "
    . "font-src 'self'; img-src 'self' data:; connect-src 'self'; "
    . "base-uri 'none'; form-action 'self'; frame-ancestors 'none'; object-src 'none'"
  );
}

function egmSecurityGetCsrfToken(): string
{
  egmSecurityEnsureSessionStarted();
  $current = $_SESSION['task_club_csrf'] ?? null;
  if (is_string($current) && $current !== '') {
    return $current;
  }
  $token = bin2hex(random_bytes(32));
  $_SESSION['task_club_csrf'] = $token;
  return $token;
}

function egmSecurityReadCsrfFromRequest(?array $payload = null, string $field = 'csrf'): string
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
  if (isset($_SERVER['HTTP_X_EGM_CSRF'])) {
    return trim((string)$_SERVER['HTTP_X_EGM_CSRF']);
  }
  return '';
}

function egmSecurityIsValidCsrfToken(string $providedToken): bool
{
  $provided = trim($providedToken);
  if ($provided === '') {
    return false;
  }
  $expected = egmSecurityGetCsrfToken();
  return $expected !== '' && hash_equals($expected, $provided);
}
