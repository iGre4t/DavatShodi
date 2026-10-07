<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/api/lib/database-instance-materializer.php';
$base = sys_get_temp_dir() . '/database-instance-materializer-draw-' . bin2hex(random_bytes(6));
mkdir($base . '/api/lib', 0775, true);
mkdir($base . '/mini apps/Event Guest Manager', 0775, true);
mkdir($base . '/mini apps/EGMs/Test Event', 0775, true);
file_put_contents($base . '/api/lib/tab-permissions.php', '<?php');
file_put_contents($base . '/api/lib/egm-period-draws.php', '<?php');
try {
    foreach (['period_draw.php', 'pot_service.php'] as $file) {
        $source = file_get_contents(dirname(__DIR__) . '/mini apps/Event Guest Manager/' . $file);
        foreach ([false, true] as $generated) {
            $content = $generated ? databaseInstanceMaterializerPatch('egm', $source, 'Test Event') : $source;
            $directory = $base . ($generated ? '/mini apps/EGMs/Test Event' : '/mini apps/Event Guest Manager');
            $variable = $file === 'period_draw.php' ? '$siteRoot' : '$root';
            $offset = $file === 'pot_service.php' ? strpos($content, 'function egmPotEligibleParticipants') : 0;
            $start = strpos($content, $variable . ' =', $offset);
            $end = strpos($content, 'require_once', $start);
            $code = substr($content, $start, $end - $start);
            eval(str_replace('__DIR__', var_export($directory, true), $code));
            $resolved = $file === 'period_draw.php' ? $siteRoot : $root;
            if (realpath($resolved) !== realpath($base)) throw new RuntimeException($file . ' resolved wrong root in ' . ($generated ? 'generated EGM' : 'template'));
        }
    }
    echo "Draw and pot include paths passed for template and generated EGM.\n";
} finally {
    databaseInstanceMaterializerRemoveTree($base);
}
