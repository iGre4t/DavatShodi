<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/api/lib/egm-refmonitor-teams.php';

if (egmRefMonitorCoverColor('', false) !== '') throw new RuntimeException('Optional color rejected');
if (count(EGM_REF_COVER_COLORS) !== 18 || count(array_unique(EGM_REF_COVER_COLORS)) !== 18) throw new RuntimeException('Palette must have 18 distinct colors');
foreach (EGM_REF_COVER_COLORS as $color) if (egmRefMonitorCoverColor('  ' . $color . '  ', true) !== $color) throw new RuntimeException('Palette color rejected');
if (egmRefMonitorCoverColor('آبی و سفید', true, 'آبی و سفید') !== 'آبی و سفید') throw new RuntimeException('Legacy saved color rejected');
if (egmRefMonitorCoverColor(str_repeat('آ', 80), true, str_repeat('آ', 80)) !== str_repeat('آ', 80)) throw new RuntimeException('Unicode length was measured in bytes');
foreach (['', '   ', 'آبی و سفید', 'نامعتبر', str_repeat('آ', 81), "آبی\nسفید", "\xff"] as $invalid) {
    try {
        egmRefMonitorCoverColor($invalid, true);
        throw new RuntimeException('Invalid required color accepted');
    } catch (InvalidArgumentException $expected) {}
}
$row = ['id'=>1, 'created_at'=>'2026-10-06'];
$view = egmRefMonitorTeamView($row, ['name'=>'Test', 'cover_color'=>'آبی و سفید']);
if ($view['cover_color'] !== 'آبی و سفید') throw new RuntimeException('Saved color missing from team response');
if (egmRefMonitorTeamView($row, ['name'=>'Legacy'])['cover_color'] !== '') throw new RuntimeException('Legacy team is not compatible');
echo "18-color palette, invalid colors, Unicode limits, team response and legacy compatibility passed.\n";
