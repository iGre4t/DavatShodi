<?php
require_once dirname(__DIR__).'/api/lib/egm-winapp-access.php';
$policy=egmWinAppPolicy('scan_and_actions');
foreach(['can_scan','can_view_event_info','can_view_logs','can_manage_scan_actions','mask_national_id'] as $key)if(!$policy[$key])throw new Exception($key);
foreach(['can_view_user_info','can_open_guest_details','can_reset_guest_entry','can_manage_settings','can_use_printer'] as $key)if($policy[$key])throw new Exception($key);
if(egmFacilitatorWindowsAccessLevel('scan_and_actions')!=='scan_and_actions')throw new Exception('New role normalized away');
echo "PASS: new Windows action level is accepted and cannot expose details, reset entry, change settings or print without the appropriate grant\n";
