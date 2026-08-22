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
    str_contains($source, 'const LOG_SYNC_INTERVAL_MS=2500')
        && str_contains($source, "document.visibilityState==='visible'")
        && str_contains($source, "document.addEventListener('visibilitychange'")
        && str_contains($source, "url.searchParams.set('action','logs_version')")
        && str_contains($source, 'version!==lastLogsVersion'),
    'Visibility-aware cross-PC log synchronization is missing.'
);
egmRealtimeAssert(
    str_contains($source, 'function egmCheckInLogsVersion(')
        && str_contains($source, "data-logs-version=\"")
        && str_contains($source, "\$getAction === 'logs_version'"),
    'Efficient change detection for cross-PC records is missing.'
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

fwrite(STDOUT, "EGM Guest Control realtime test passed.\n");
