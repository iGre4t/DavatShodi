<?php
require_once dirname(__DIR__).'/api/lib/panel-dashboard.php';
class DashboardStatement extends PDOStatement {
 public function fetchColumn(int $column=0): mixed {return json_encode([['tagCode'=>'01','title'=>'روز اول — پذیرش مهمانان','active'=>true,'duration'=>false]]);}
}
class DashboardPdo extends PDO {
 public function __construct() {}
 public function query(string $query,?int $fetchMode=null,mixed ...$fetchModeArgs): PDOStatement|false {return new DashboardStatement();}
}
$userPdo=new DashboardPdo();$eventGuestManagerCreatorEnabled=false;$taskClubCreatorEnabled=true;
$eventGuestManagerTabs=[['id'=>'event-guest-manager','code'=>'12345','name'=>'رویداد هم‌کوک','sidebarVisible'=>true],['id'=>'event-guest-manager-mission-67890','code'=>'67890','name'=>'رویداد مسیر صعود','sidebarVisible'=>true]];
$taskClubMissionTabs=[['id'=>'task-club-mission-test','name'=>'باشگاه همراهان','sidebarVisible'=>true]];
$currentUser=['fullname'=>'آزاده محمدی','permissions'=>['home','event-guest-manager','event-guest-manager:games','event-guest-manager:manage-tasks','event-guest-manager:guest-reports']];
echo '<html dir="rtl"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>body{margin:0;padding:24px;font-family:Tahoma;background:#f8fafc}.btn{font:inherit;cursor:pointer;padding:10px;border:1px solid #ddd;background:white}button{font-family:inherit}';
echo file_get_contents(dirname(__DIR__).'/style/event-dashboard.css');
echo '</style><section id="tab-home">';
require dirname(__DIR__).'/panel-dashboard-view.php';
echo '</section></html>';
