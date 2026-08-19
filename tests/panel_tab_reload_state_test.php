<?php
declare(strict_types=1);

function panelTabStateAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
$panelPhp = file_get_contents($root . '/panel.php');
$appJs = file_get_contents($root . '/app.js');
panelTabStateAssert(is_string($panelPhp), 'panel.php could not be read');
panelTabStateAssert(is_string($appJs), 'app.js could not be read');

panelTabStateAssert(
    str_contains($panelPhp, "\$_GET['tab']") && str_contains($panelPhp, '$requestedInitialTab'),
    'panel.php does not restore a requested tab during server rendering'
);
panelTabStateAssert(
    str_contains($appJs, 'url.searchParams.set("tab", safeTab)'),
    'tab activation does not store the active tab in the URL'
);
panelTabStateAssert(
    str_contains($appJs, 'window.history.pushState(nextState, "", url)')
        && str_contains($appJs, 'window.history.replaceState(nextState, "", url)'),
    'tab navigation does not maintain browser history safely'
);
panelTabStateAssert(
    str_contains($appJs, 'window.addEventListener("popstate"')
        && str_contains($appJs, 'activateTab(readPanelTabFromUrl(), { historyMode: "none" })'),
    'Back and Forward navigation does not restore the matching panel tab'
);

fwrite(STDOUT, "Panel tab reload state test passed.\n");
