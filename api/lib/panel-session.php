<?php
declare(strict_types=1);

function panelSessionLifetime(): int
{
    $seconds=(int)ini_get('session.gc_maxlifetime');
    return $seconds>0?$seconds:1440;
}

function panelSessionInitialize(?int $now=null): void
{
    $now??=time();
    if (empty($_SESSION['authenticated']) || !is_array($_SESSION['user']??null)) return;
    $_SESSION['panel_session_expires_at']=$now+panelSessionLifetime();
    $_SESSION['panel_session_keepalive_csrf']??=bin2hex(random_bytes(24));
}

/** Only sessions opened by panel.php/login.php have a panel deadline. */
function panelSessionExpired(?int $now=null): bool
{
    return isset($_SESSION['panel_session_expires_at']) && ($now??time())>=(int)$_SESSION['panel_session_expires_at'];
}

function panelSessionClearExpired(?int $now=null): bool
{
    if (!panelSessionExpired($now)) return false;
    $_SESSION=[];
    return true;
}

function panelSessionState(?int $now=null): array
{
    $now??=time();
    panelSessionClearExpired($now);
    if (empty($_SESSION['authenticated']) || !is_array($_SESSION['user']??null)) return ['authenticated'=>false,'server_now'=>$now];
    if (!isset($_SESSION['panel_session_expires_at'])) panelSessionInitialize($now);
    return ['authenticated'=>true,'server_now'=>$now,'expires_at'=>(int)$_SESSION['panel_session_expires_at'],'warning_seconds'=>min(120,max(1,intdiv(panelSessionLifetime(),2))),'csrf'=>(string)$_SESSION['panel_session_keepalive_csrf']];
}
