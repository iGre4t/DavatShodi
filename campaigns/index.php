<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/lib/campaign-redirects.php';
require_once __DIR__ . '/../api/lib/utm-branches.php';

function campaignRequestPath(): string
{
    $queryCampaign = $_GET['campaign'] ?? '';
    if (trim((string)$queryCampaign) !== '') {
        return campaignRedirectsNormalizePath($queryCampaign);
    }

    $requestPath = parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    if (!is_string($requestPath) || $requestPath === '') {
        return '';
    }

    $requestPath = rawurldecode($requestPath);
    $scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/campaigns/index.php'));
    $basePath = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
    if ($basePath !== '' && $basePath !== '.' && strpos($requestPath, $basePath . '/') === 0) {
        return campaignRedirectsNormalizePath(substr($requestPath, strlen($basePath) + 1));
    }

    return '';
}

$requestPath = campaignRequestPath();
$redirect = campaignRedirectsFind($requestPath);
if ($redirect !== null) {
    header('Location: ' . $redirect['target'], true, (int)$redirect['status_code']);
    exit;
}

$utmBranch = utmBranchesResolveRequestPath($requestPath);
if ($utmBranch !== null) {
    $branch = $utmBranch['branch'];
    $redirect = $utmBranch['redirect'];
    utmBranchesWriteVisitLog($branch, $redirect);
    header('Location: ' . $redirect['target'], true, (int)$redirect['status_code']);
    exit;
}

http_response_code(404);
header('Content-Type: text/plain; charset=UTF-8');
echo 'Campaign not found.';
