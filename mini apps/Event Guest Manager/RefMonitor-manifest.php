<?php
header('Content-Type: application/manifest+json; charset=UTF-8');
header('Cache-Control: no-cache');
echo json_encode(['id'=>'./RefMonitor.php','name'=>'پنل تسهیلگر','short_name'=>'تسهیلگر','lang'=>'fa','dir'=>'rtl','start_url'=>'./RefMonitor.php','scope'=>'./','display'=>'standalone','background_color'=>'#ffffff','theme_color'=>'#ffffff','icons'=>[['src'=>'RefMonitor-icon.php','sizes'=>'512x512','type'=>'image/png','purpose'=>'any']]],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
