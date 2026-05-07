<?php
declare(strict_types=1);

function tcSecurityEnsureSessionStarted(): void
{
  if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
  }
}

function tcSecurityGetCsrfToken(): string
{
  tcSecurityEnsureSessionStarted();
  $current = $_SESSION['task_club_csrf'] ?? null;
  if (is_string($current) && $current !== '') {
    return $current;
  }
  try {
    $token = bin2hex(random_bytes(32));
  } catch (Throwable $e) {
    $token = hash('sha256', uniqid('task_club_csrf_', true));
  }
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
