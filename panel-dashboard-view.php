<?php
$dashboardCatalog=$eventGuestManagerTabs;
if ($eventGuestManagerCreatorEnabled) $dashboardCatalog[]=['id'=>'event-guest-manager','name'=>'رویداد توسعه','code'=>EGM_DEVELOP_CODE,'sidebarVisible'=>true];
$dashboardEvents=panelDashboardEvents($userPdo,$dashboardCatalog);
$dashboardClubs=array_values(array_filter($taskClubMissionTabs,static fn($club)=>$club['sidebarVisible']??true));
$dashboardEscape=static fn($value)=>htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');
$dashboardNumber=static fn($value)=>strtr((string)$value,['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹']);
$dashboardActive=count(array_filter($dashboardEvents,static fn($event)=>$event['state']==='active'));
$dashboardName=trim((string)($currentUser['fullname']??'')) ?: (string)($currentUser['username']??'');
$dashboardShortcut=static function($tab,$label,$pane='') use($dashboardEscape) {
    echo '<button type="button" class="btn" data-dashboard-tab="'.$dashboardEscape($tab).'" data-dashboard-pane="'.$dashboardEscape($pane).'">'.$dashboardEscape($label).'</button>';
};
?>
<div class="event-dashboard">
  <header class="ed-welcome">
    <div><span class="ed-eyebrow">میز کار رویدادها</span><h1>سلام <?= $dashboardEscape($dashboardName) ?> 👋</h1><p>امروز، رویدادهایت را از اینجا مدیریت کن.</p></div>
    <div class="ed-create"><?php if($eventGuestManagerCreatorEnabled) $dashboardShortcut('event-guest-manager-creator','مدیریت رویدادها'); ?></div>
  </header>
  <div class="ed-stats" aria-label="خلاصه رویدادها">
    <div><i class="ri ri-pulse-line" aria-hidden="true"></i><strong><?= $dashboardNumber($dashboardActive) ?></strong><span>رویداد در حال اجرا</span></div>
    <div><i class="ri ri-calendar-event-line" aria-hidden="true"></i><strong><?= $dashboardNumber(count($dashboardEvents)) ?></strong><span>رویداد در دسترس</span></div>
    <div><i class="ri ri-team-line" aria-hidden="true"></i><strong><?= $dashboardNumber(count($dashboardClubs)) ?></strong><span>باشگاه تعاملی</span></div>
  </div>
  <section aria-labelledby="ed-events-title"><div class="ed-section-heading"><div><span class="ed-eyebrow">اولویت امروز</span><h2 id="ed-events-title">رویدادهای شما</h2></div><span class="ed-subtle">رویدادهای فعال در ابتدای فهرست</span></div>
    <div class="ed-events-grid">
    <?php foreach($dashboardEvents as $event): $active=$event['state']==='active'; $period=$event['activePeriod']??$event['nextPeriod']; $tab=$event['id']; ?>
      <article class="ed-event<?= $active?' is-live':'' ?>">
        <div class="ed-event-heading"><span class="ed-event-icon ri ri-calendar-event-line" aria-hidden="true"></span><span class="ed-badge<?= $active?' live':'' ?>"><?= $active?'در حال اجرا':($event['state']==='multiple_active_periods'?'تداخل بازه‌ها':($event['state']==='unavailable'?'وضعیت در دسترس نیست':'بدون بازهٔ فعال')) ?></span></div>
        <h3><?= $dashboardEscape($event['name']) ?></h3>
        <div class="ed-period"><span><?= $active?'بازهٔ فعال':($period?'بازهٔ بعدی':'بازه‌ها') ?></span><strong><?= $dashboardEscape($period['title']??($event['state']==='multiple_active_periods'?'بازه‌های فعال را بررسی کنید':'بازهٔ فعالی وجود ندارد')) ?></strong>
        <?php if($period && !empty($period['duration'])): ?><time><?= $dashboardEscape($period['startDate']??'') ?> <span dir="ltr"><?= $dashboardEscape($period['startTime']??'') ?></span></time><?php endif; ?></div>
        <div class="ed-event-actions"><?php $dashboardShortcut($tab,'ورود به رویداد');
          if(userHasPermissionId($currentUser,$tab.':scan')) $dashboardShortcut($tab,'اسکن مهمانان','egm-scan');
          if(userHasPermissionId($currentUser,$tab.':manage-tasks')) $dashboardShortcut($tab,'بازه‌ها','egm-manage-tasks');
          if(userHasPermissionId($currentUser,$tab.':guest-reports')) $dashboardShortcut($tab,'گزارش مهمانان','egm-guest-reports');
          if(userHasPermissionId($currentUser,$tab.':games')) $dashboardShortcut($tab,'بازی‌ها و اتاق‌ها','egm-games');
        ?></div>
        <?php if($active && $period && userHasPermissionId($currentUser,$tab.':manage-tasks')): ?><button type="button" class="ed-period-link" data-dashboard-tab="<?= $dashboardEscape($tab) ?>" data-dashboard-pane="egm-task-<?= $dashboardEscape(trim(preg_replace('/-+/','-',preg_replace('/[^a-z0-9_-]+/','-',strtolower(egmCheckInPeriodCode($period)))),'-')) ?>">مدیریت <?= $dashboardEscape($period['title']??'بازهٔ فعال') ?> <i class="ri ri-arrow-left-line" aria-hidden="true"></i></button><?php endif; ?>
      </article>
    <?php endforeach; ?>
    </div>
    <?php if(!$dashboardEvents): ?><div class="ed-empty"><i class="ri ri-calendar-event-line" aria-hidden="true"></i><h3>رویدادی برای نمایش ندارید</h3><p><?= $eventGuestManagerCreatorEnabled?'اولین رویداد خود را بسازید.':'رویدادهای دارای دسترسی شما اینجا نمایش داده می‌شوند.' ?></p><?php if($eventGuestManagerCreatorEnabled) $dashboardShortcut('event-guest-manager-creator','ساخت رویداد'); ?></div><?php endif; ?>
  </section>
  <section aria-labelledby="ed-clubs-title"><div class="ed-section-heading"><h2 id="ed-clubs-title">باشگاه‌های تعاملی</h2><?php if($taskClubCreatorEnabled) $dashboardShortcut('task-club-creator','مدیریت باشگاه‌ها'); ?></div>
    <div class="ed-clubs-grid"><?php foreach($dashboardClubs as $club): ?><button type="button" class="ed-club" data-dashboard-tab="<?= $dashboardEscape($club['id']) ?>"><span class="ed-club-icon ri ri-team-line" aria-hidden="true"></span><strong><?= $dashboardEscape($club['name']) ?></strong><i class="ri ri-arrow-left-line" aria-hidden="true"></i></button><?php endforeach; ?></div>
    <?php if(!$dashboardClubs): ?><p class="ed-subtle">باشگاه تعاملی‌ای برای نمایش ندارید.</p><?php endif; ?>
  </section>
  <p class="ed-navigation-status" role="status" aria-live="polite"></p>
</div>
