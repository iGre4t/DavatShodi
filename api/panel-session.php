<?php
declare(strict_types=1);
require_once __DIR__.'/lib/panel-session.php';
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
$state=panelSessionState();
if (!$state['authenticated']) {
    header('X-Panel-Session-Expired: 1'); http_response_code(401);
    echo json_encode(['status'=>'error','code'=>'session_expired']+$state);exit;
}
if (($_SERVER['REQUEST_METHOD']??'GET')==='POST') {
    $input=json_decode(file_get_contents('php://input')?:'{}',true);
    if (!is_array($input) || !hash_equals($state['csrf'],(string)($input['csrf']??'')) || ($input['action']??'')!=='continue') {
        http_response_code(403);echo json_encode(['status'=>'error','message'=>'توکن امنیتی نامعتبر است.']);exit;
    }
    panelSessionInitialize();$state=panelSessionState();
}
session_write_close();
echo json_encode(['status'=>'ok']+$state);
