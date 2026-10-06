<?php
$root=__DIR__;
while(!is_file($root.'/api/config.php')){$parent=dirname($root);if($parent===$root){http_response_code(404);exit;}$root=$parent;}
header('Content-Type: image/png');header('Cache-Control: public, max-age=86400');readfile($root.'/assets/refmonitor-pwa-icon.png');
