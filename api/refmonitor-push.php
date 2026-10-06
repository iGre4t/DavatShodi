<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/lib/egm-refmonitor-teams.php';
$pdo=connectDatabase(loadConfig(__DIR__.'/config.php'));
if(!$pdo){fwrite(STDERR,"Database unavailable.\n");exit(1);}
egmRefPushFlush($pdo);
