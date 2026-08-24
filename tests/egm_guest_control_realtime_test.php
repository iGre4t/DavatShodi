<?php
declare(strict_types=1);

function egmRealtimeAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$source = file_get_contents(dirname(__DIR__) . '/api/lib/egm-check-in.php');
egmRealtimeAssert(is_string($source), 'Could not read the EGM Guest Control source.');

egmRealtimeAssert(
    str_contains($source, 'const scanQueue=[]')
        && str_contains($source, 'scanQueue.push(guestCode)')
        && str_contains($source, 'while(scanQueue.length>0)')
        && str_contains($source, 'const guestCode=scanQueue.shift()'),
    'The FIFO scanner queue is missing or no longer processes scans in order.'
);
egmRealtimeAssert(
    !str_contains($source, 'lastSubmittedCode')
        && !str_contains($source, 'input.disabled=true'),
    'Guest Control can still discard scans by locking or deduplicating the input during processing.'
);
egmRealtimeAssert(
    str_contains($source, 'input.value=\'\';resetScannerState();input.focus()')
        && str_contains($source, 'void processScanQueue()'),
    'A completed scan no longer releases the input immediately for the next scanner.'
);
egmRealtimeAssert(
    str_contains($source, "pageScannerBuffer=''")
        && str_contains($source, 'captureScannerSource=')
        && str_contains($source, 'restoreScannerSource=')
        && str_contains($source, "document.addEventListener('keydown'")
        && str_contains($source, 'pageScannerFastGaps<SCANNER_MIN_FAST_GAPS')
        && str_contains($source, 'input.value=capturedCode')
        && str_contains($source, 'dialog?.open||walkInDialog?.open'),
    'Page-level scanner capture no longer restores focus and forwards off-field scans safely.'
);
egmRealtimeAssert(
    str_contains($source, '.scanner input.scanner-captured')
        && str_contains($source, '@keyframes egm-scanner-captured'),
    'The visual confirmation for an automatically captured scan is missing.'
);
egmRealtimeAssert(
    str_contains($source, 'data-scan-toasts')
        && str_contains($source, 'const SCAN_TOAST_DURATION_MS=4000')
        && str_contains($source, 'showScanFeedback=')
        && str_contains($source, 'showScanFeedback(data,guestCode)')
        && str_contains($source, "forcedTone||cls(status)")
        && str_contains($source, '.scan-toast.success')
        && str_contains($source, '.scan-toast.duplicate'),
    'Four-second status-colored scan toasts are missing.'
);
egmRealtimeAssert(
    str_contains($source, 'window.AudioContext||window.webkitAudioContext')
        && str_contains($source, 'playScanSound=')
        && str_contains($source, "successful?'sine':'triangle'")
        && str_contains($source, "playScanSound(tone==='success')"),
    'The distinct success and failure scan sounds are missing.'
);
egmRealtimeAssert(
    str_contains($source, 'const LOG_SYNC_INTERVAL_MS=2500')
        && str_contains($source, "document.visibilityState==='visible'")
        && str_contains($source, "document.addEventListener('visibilitychange'")
        && str_contains($source, "url.searchParams.set('action','logs_version')")
        && str_contains($source, 'version!==lastLogsVersion'),
    'Visibility-aware cross-PC log synchronization is missing.'
);
egmRealtimeAssert(
    str_contains($source, 'function egmCheckInStatsVersion(')
        && str_contains($source, 'data-stats-version=')
        && str_contains($source, "url.searchParams.set('action','stats')")
        && str_contains($source, 'statsVersion!==lastStatsVersion')
        && str_contains($source, "'stats_version' => egmCheckInStatsVersion(\$context)"),
    'Core roster changes can no longer refresh Guest Control statistics independently of logs.'
);
egmRealtimeAssert(
    str_contains($source, 'function egmCheckInPreviousAttendance(')
        && str_contains($source, "'attended_previous_period'")
        && str_contains($source, 'previous_attendance')
        && str_contains($source, 'showPreviousAttendanceAlert(data.previous_attendance)'),
    'Cross-period attendance warnings are missing from Guest Control.'
);
egmRealtimeAssert(
    str_contains($source, 'function egmCheckInLogsVersion(')
        && str_contains($source, "data-logs-version=\"")
        && str_contains($source, "\$getAction === 'logs_version'"),
    'Efficient change detection for cross-PC records is missing.'
);
egmRealtimeAssert(
    str_contains($source, 'function egmCheckInDashboardStats(')
        && str_contains($source, 'function egmCheckInGenderGroup(')
        && str_contains($source, 'data-initial-stats=')
        && str_contains($source, 'data-attendance-stats')
        && str_contains($source, 'data-stat="waiting"')
        && str_contains($source, 'data-stat="inside"')
        && str_contains($source, 'data-stat="quit"')
        && str_contains($source, 'data-stat="walk_in_total"')
        && str_contains($source, 'data-stat="walk_in_entered"')
        && str_contains($source, 'data-gender="male"')
        && str_contains($source, 'data-gender="female"')
        && str_contains($source, 'renderStats(data.stats)')
        && str_contains($source, "url.searchParams.set('include_stats','1')"),
    'The compact live attendance and gender statistics interface is missing.'
);
egmRealtimeAssert(
    str_contains($source, "cache:'no-store'")
        && str_contains($source, "header('Cache-Control: no-store"),
    'Realtime log responses can be served from a stale browser or proxy cache.'
);
egmRealtimeAssert(
    substr_count($source, 'FOR UPDATE') >= 3,
    'Database row locking no longer protects concurrent scanner requests.'
);

require_once dirname(__DIR__) . '/api/lib/egm-check-in.php';
final class EgmFailingStatsPdo extends PDO
{
    public function __construct() {}

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        throw new PDOException('simulated optional statistics failure');
    }
}
$failedStats = egmCheckInDashboardStats([
    'pdo' => new EgmFailingStatsPdo(),
    'period_code' => '01',
    'tables' => ['users' => 'users', 'user_periods' => 'user_periods'],
]);
egmRealtimeAssert(
    ($failedStats['active'] ?? true) === false && ($failedStats['total'] ?? -1) === 0,
    'An optional statistics query failure can still interrupt Guest Control.'
);

fwrite(STDOUT, "EGM Guest Control realtime test passed.\n");
