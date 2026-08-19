<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'mini apps' . DIRECTORY_SEPARATOR . 'Task Club';
$files = glob($root . DIRECTORY_SEPARATOR . '*.php') ?: [];
$files = array_merge($files, glob($root . DIRECTORY_SEPARATOR . 'useractivitylogs' . DIRECTORY_SEPARATOR . '*.php') ?: []);
$replacements = [
    'file_get_contents' => 'tcDbFileGetContents',
    'file_put_contents' => 'tcDbFilePutContents',
    'is_file' => 'tcDbIsFile',
    'file_exists' => 'tcDbFileExists',
    'fopen' => 'tcDbFopen',
    'filemtime' => 'tcDbFilemtime',
    'filesize' => 'tcDbFilesize',
    'unlink' => 'tcDbUnlink',
    'rename' => 'tcDbRename',
    'copy' => 'tcDbCopy',
    'glob' => 'tcDbGlob',
];
$updated = [];
foreach ($files as $path) {
    if (basename($path) === 'tc-database-runtime.php') continue;
    $source = file_get_contents($path);
    if (!is_string($source)) throw new RuntimeException('Unable to read ' . $path);
    $tokens = token_get_all($source);
    $output = '';
    $count = count($tokens);
    for ($index = 0; $index < $count; $index++) {
        $token = $tokens[$index];
        if (is_array($token) && $token[0] === T_STRING && isset($replacements[strtolower($token[1])])) {
            $next = $index + 1;
            while ($next < $count && is_array($tokens[$next]) && $tokens[$next][0] === T_WHITESPACE) $next++;
            if (($tokens[$next] ?? null) === '(') $token[1] = $replacements[strtolower($token[1])];
        }
        $output .= is_array($token) ? $token[1] : $token;
    }
    $relative = str_replace('\\', '/', substr($path, strlen($root) + 1));
    $requireLine = str_starts_with($relative, 'useractivitylogs/')
        ? "require_once dirname(__DIR__) . '/tc-database-runtime.php';"
        : "require_once __DIR__ . '/tc-database-runtime.php';";
    if (!str_contains($output, $requireLine)) {
        $patched = preg_replace(
            '/(declare\s*\(\s*strict_types\s*=\s*1\s*\)\s*;\s*)/',
            "$1\n{$requireLine}\n",
            $output,
            1
        );
        if (!is_string($patched)) throw new RuntimeException('Unable to add runtime bootstrap to ' . $relative);
        if ($patched === $output) $patched = preg_replace('/^(?:\xEF\xBB\xBF)?<\?php\s*/', "<?php\n{$requireLine}\n", $output, 1);
        if (!is_string($patched) || $patched === $output) throw new RuntimeException('Unable to add runtime bootstrap to ' . $relative);
        $output = $patched;
    }
    if ($output !== $source) {
        if (file_put_contents($path, $output, LOCK_EX) === false) throw new RuntimeException('Unable to update ' . $path);
        $updated[] = $relative;
    }
}

$egmSafetyPath = dirname(__DIR__) . '/mini apps/Event Guest Manager/invitees_csv_safety.php';
$tcSafetyPath = $root . '/invitees_csv_safety.php';
$safety = file_get_contents($egmSafetyPath);
if (!is_string($safety)) throw new RuntimeException('Unable to read the database-locking invitee transaction helper.');
$safety = str_replace(['EGM', 'Egm', 'egm'], ['TC', 'Tc', 'tc'], $safety);
if (file_put_contents($tcSafetyPath, $safety, LOCK_EX) === false) {
    throw new RuntimeException('Unable to install the TaskClub database-locking invitee transaction helper.');
}
echo json_encode(['status' => 'ok', 'updated' => count($updated), 'files' => $updated], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
