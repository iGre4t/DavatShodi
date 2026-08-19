<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/api/lib/egm-invite-card-routes.php';

function egmPublicRouteAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$code = 'FvLdJAnAIa2oRhdA0002001';
$directory = 'mini apps/EGMs/رویداد مسیر صعود 1405';
$asset = 'InviteCards/' . $code . '.jpg';
$stored = $directory . '/egm_asset.php?path=' . rawurlencode($asset);

egmPublicRouteAssert(
    egmInviteCardDatabaseAssetRelative($stored, $directory, $code) === $asset,
    'The database-backed invite-card path was not recognized.'
);
egmPublicRouteAssert(
    egmInviteCardDatabaseAssetRelative($stored, $directory, $code . 'x') === '',
    'A stored asset URL was accepted for a different invite code.'
);
egmPublicRouteAssert(
    egmInviteCardDatabaseAssetRelative('../egm_asset.php?path=' . rawurlencode($asset), $directory, $code) === '',
    'A path outside the registered EGM directory was accepted.'
);

$router = file_get_contents(dirname(__DIR__) . '/Invited/index.php');
egmPublicRouteAssert(is_string($router), 'The public Invite Card router could not be read.');
egmPublicRouteAssert(str_contains($router, 'egmDatabaseRuntimeRead($destination)'), 'Legacy DB cards are not restored to disk.');
egmPublicRouteAssert(str_contains($router, 'egmDatabaseRuntimeDelete($destination)'), 'Legacy DB card blobs are not removed after restoration.');
egmPublicRouteAssert(str_contains($router, "'/InviteCards/' . \$inviteCode . '.jpg'"), 'The router does not use a physical InviteCards path.');

$generator = file_get_contents(dirname(__DIR__) . '/api/lib/egm-period-invite-cards.php');
egmPublicRouteAssert(is_string($generator), 'The Invite Card generator could not be read.');
egmPublicRouteAssert(!str_contains($generator, 'egmDatabaseRuntimeWrite($destination'), 'Generated card images are still written to the database.');
egmPublicRouteAssert(str_contains($generator, "'/InviteCards/' . \$inviteCode . '.jpg'"), 'Generated cards do not store a physical web path.');
egmPublicRouteAssert(str_contains($generator, 'egmDatabaseRuntimeDelete($destination)'), 'A legacy card blob is not removed after writing the physical JPG.');

$storage = file_get_contents(dirname(__DIR__) . '/api/lib/egm-instance-storage.php');
egmPublicRouteAssert(is_string($storage), 'The EGM instance storage implementation could not be read.');
$scanStart = strpos($storage, 'function egmInstanceScanRuntimeFiles');
$scanEnd = strpos($storage, 'function egmInstanceRuntimeFileDataKey', $scanStart === false ? 0 : $scanStart);
egmPublicRouteAssert($scanStart !== false && $scanEnd !== false, 'The EGM runtime scan implementation was not found.');
$scanSource = substr($storage, $scanStart, $scanEnd - $scanStart);
egmPublicRouteAssert(!str_contains($scanSource, "'InviteCards'"), 'Physical Invite Card JPGs are still scanned into database storage.');
egmPublicRouteAssert(
    str_contains($storage, "str_starts_with(strtolower(\$existingPath), 'invitecards/')"),
    'A general runtime sync can discard legacy Invite Card blobs before restoration.'
);

fwrite(STDOUT, "EGM public Invite Card route test passed.\n");
