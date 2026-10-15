<?php
declare(strict_types=1);
require_once __DIR__ . '/egm-database-runtime.php';
require_once __DIR__ . '/egm-security.php';
require_once dirname(__DIR__, 2) . '/api/lib/tab-permissions.php';
require_once dirname(__DIR__, 2) . '/api/lib/egm-guest-reports.php';
$user = requireTabPermissionFromSession('event-guest-manager',true);
if (!userHasPermissionId($user,'event-guest-manager:guest-reports')) denyPanelAccess(403,'دسترسی بررسی مهمانان مجاز نیست.',true);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
try {
    $method=$_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (!in_array($method,['GET','POST'],true)) {http_response_code(405);exit;}
    $input=$method==='POST' ? json_decode((string)file_get_contents('php://input'),true) : $_GET;
    if (!is_array($input)) throw new InvalidArgumentException('درخواست نامعتبر است.');
    if ($method==='POST' && !egmSecurityIsValidCsrfToken(egmSecurityReadCsrfFromRequest($input))) denyPanelAccess(403,'توکن امنیتی نامعتبر است.',true);
    $context=egmCheckInContext(dirname(__DIR__, 2),__DIR__);
    $action=(string)($input['action'] ?? 'list');
    if (session_status()===PHP_SESSION_ACTIVE) session_write_close();
    if ($action==='list') $result=egmGuestReportsList($context,$input);
    elseif (in_array($action,['resolve','reopen'],true) && $method==='POST') {
        $id=(string)($input['id'] ?? '');
        if (!preg_match('/^[a-f0-9]{32}$/D',$id)) throw new InvalidArgumentException('گزارش نامعتبر است.');
        $find=$context['pdo']->prepare('SELECT egm_code FROM egm_telegram_reports WHERE id=?');$find->execute([$id]);
        if ($find->fetchColumn()!==$context['code']) throw new InvalidArgumentException('گزارش متعلق به این رویداد نیست.');
        $choice=$action==='reopen' ? 'reopen' : (string)($input['choice'] ?? '');
        $report=systemTelegramDecide($context['pdo'],$id,$choice,'0',$context,$user);
        $result=['message'=>$action==='reopen' ? 'گزارش به بررسی برگشت.' : 'گزارش حل شد.'];
    } else throw new InvalidArgumentException('عملیات معتبر نیست.');
    $response=json_encode(['status'=>'ok']+$result,JSON_UNESCAPED_UNICODE|JSON_HEX_TAG);
    if (isset($report)) {
        // The decision is committed and bot edits are queued. Release the browser
        // before contacting external bots so slow delivery cannot lock the controls.
        ignore_user_abort(true);
        header('Content-Length: '.strlen($response));
        echo $response;
        while (ob_get_level()>0) ob_end_flush();
        flush();
        if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
        elseif (function_exists('litespeed_finish_request')) litespeed_finish_request();
        try { egmGuestReportsRefreshBots($context['pdo'],$report); }
        catch (Throwable $deliveryError) { error_log('EGM guest report bot edit queued: '.$report['id']); }
        exit;
    }
    echo $response;
} catch (InvalidArgumentException $error) {
    http_response_code(422);echo json_encode(['status'=>'error','message'=>$error->getMessage()],JSON_UNESCAPED_UNICODE);
} catch (Throwable $error) {
    error_log('EGM guest reports: '.$error->getMessage());http_response_code(500);echo json_encode(['status'=>'error','message'=>'بررسی گزارش انجام نشد؛ دوباره تلاش کنید.'],JSON_UNESCAPED_UNICODE);
}
