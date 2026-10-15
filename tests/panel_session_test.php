<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/api/lib/panel-session.php';
function sessionCheck(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$_SESSION=['authenticated'=>true,'user'=>['code'=>'test']];panelSessionInitialize(1000);$deadline=$_SESSION['panel_session_expires_at'];$csrf=$_SESSION['panel_session_keepalive_csrf'];
sessionCheck($deadline===1000+panelSessionLifetime(),'Uses configured PHP lifetime');
$state=panelSessionState(1001);sessionCheck($state['authenticated']&&$state['expires_at']===$deadline,'Status polling does not renew');
sessionCheck(!panelSessionExpired($deadline-1)&&panelSessionExpired($deadline),'Exact expiry boundary');
panelSessionInitialize(1005);sessionCheck($_SESSION['panel_session_expires_at']===1005+panelSessionLifetime()&&$_SESSION['panel_session_keepalive_csrf']===$csrf,'Continue renews existing session');
$state=panelSessionState($_SESSION['panel_session_expires_at']);sessionCheck(!$state['authenticated']&&empty($_SESSION['authenticated'])&&!isset($_SESSION['user']),'Expired session clears identity and cannot revive');
$_SESSION=['authenticated'=>true,'user'=>['code'=>'winapp']];sessionCheck(!panelSessionClearExpired(9999999)&&!empty($_SESSION['authenticated']),'Unrelated sessions without panel deadline remain intact');
echo "PASS: configured deadline, read-only polling, renewal, exact expiry, identity cleanup and separate session isolation\n";
