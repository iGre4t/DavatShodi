<?php
declare(strict_types=1);

$relativePath = trim(str_replace('\\', '/', (string)($_GET['path'] ?? '')), '/');
$segments = $relativePath === '' ? [] : explode('/', $relativePath);
if (!$segments || array_filter($segments, static function (string $segment): bool {
    return $segment === '' || $segment === '.' || $segment === '..'
        || preg_match('/[\x00-\x1F\x7F]/', $segment) === 1;
})) {
    http_response_code(404);
    exit;
}

$scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/legacy-egm-path.php'));
$projectBase = rtrim(str_replace('/legacy-egm-path.php', '', $scriptName), '/');
$encodedPath = implode('/', array_map('rawurlencode', $segments));
$query = $_GET;
unset($query['path']);
$location = $projectBase . '/mini%20apps/EGMs/' . $encodedPath;
if ($query) {
    $location .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
}
// 308 keeps the original HTTP method/body, so cached JavaScript that still
// posts to an old endpoint remains compatible during the transition.
header('Location: ' . $location, true, 308);
exit;
