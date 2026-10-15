<?php
require_once __DIR__ . '/../api/lib/egm-refmonitor-teams.php';
egmRefMonitorAssertTeamOwner(['created_by'=>'facilitator:one'], 'facilitator:one');
foreach ([['created_by'=>'facilitator:two'], ['created_by'=>'admin1'], [], ['created_by'=>'']] as $payload) {
    try { egmRefMonitorAssertTeamOwner($payload, 'facilitator:one'); throw new RuntimeException('Unauthorized team allowed'); }
    catch (InvalidArgumentException $expected) {}
}
try { egmRefMonitorAssertTeamOwner(['created_by'=>''], ''); throw new RuntimeException('Empty identity allowed'); }
catch (InvalidArgumentException $expected) {}
echo "Team ownership: own team allowed; other, admin, missing and empty identities rejected PASS\n";
