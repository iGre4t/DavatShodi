<?php
declare(strict_types=1);

require_once __DIR__ . '/egm-registry.php';
require_once __DIR__ . '/tc-registry.php';

function databaseInstanceMaterializerRelative(string $root, string $path): string
{
    return trim(str_replace('\\', '/', substr($path, strlen(rtrim($root, DIRECTORY_SEPARATOR)) + 1)), '/');
}

function databaseInstanceMaterializerShouldCopy(string $kind, string $relative, bool $isDirectory): bool
{
    $relative = trim(str_replace('\\', '/', $relative), '/');
    if ($relative === '') return true;
    $excludedRoot = $kind === 'egm'
        ? ['EGMCreator.php', 'panel.php', 'mission.json']
        : ['TCCreator.php', 'panel.php', 'mission.json'];
    if (in_array($relative, $excludedRoot, true)) return false;
    if ($isDirectory) return true;
    if (preg_match('/\.json$/i', $relative) === 1) return false;

    $runtimeRoots = $kind === 'egm'
        ? ['EGM Event/', 'tasks/', 'data/pots/', 'InviteCards/', 'useractivitylogs/logs/']
        : ['TC Event/', 'tasks/', 'data/pots/', 'InviteCards/', 'useractivitylogs/logs/'];
    foreach ($runtimeRoots as $runtimeRoot) {
        if (!str_starts_with($relative, $runtimeRoot)) continue;
        return in_array($relative, [
            'InviteCards/.htaccess',
            'useractivitylogs/logs/.htaccess',
            'useractivitylogs/logs/.gitkeep',
        ], true);
    }
    return true;
}

function databaseInstanceMaterializerPatch(string $kind, string $content, string $folder): string
{
    if ($kind === 'egm') {
        $webPath = 'mini%20apps/EGMs/' . rawurlencode($folder);
        $directory = 'mini apps/EGMs/' . $folder;
        $sessionFrom = "session_name('EGMSESSID');";
        $sessionTo = "session_name('EGM' . substr(hash('sha256', __DIR__), 0, 12));";
        $urlFrom = 'mini%20apps/Event%20Guest%20Manager';
        $directoryFrom = 'mini apps/Event Guest Manager';
    } else {
        $webPath = 'mini%20apps/missions/' . rawurlencode($folder);
        $directory = 'mini apps/missions/' . $folder;
        $sessionFrom = "session_name('TASKCLUBSESSID');";
        $sessionTo = "session_name('TASKCLUB' . substr(hash('sha256', __DIR__), 0, 12));";
        $urlFrom = 'mini%20apps/Task%20Club';
        $directoryFrom = 'mini apps/Task Club';
    }
    return str_replace([
        $urlFrom, $directoryFrom,
        "__DIR__ . '/../../", '__DIR__ . "/../../', 'dirname(__DIR__, 2)',
        $sessionFrom, 'return "../../{$trimmed}";',
        "src: url('../../style/", "url('../../style/", 'href="../../style/', "= '../../style/",
    ], [
        $webPath, $directory,
        "__DIR__ . '/../../../", '__DIR__ . "/../../../', 'dirname(__DIR__, 3)',
        $sessionTo, 'return "../../../{$trimmed}";',
        "src: url('../../../style/", "url('../../../style/", 'href="../../../style/', "= '../../../style/",
    ], $content);
}

function databaseInstanceMaterializerRemoveTree(string $path): void
{
    if (!is_dir($path)) return;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        if ($item->isDir() && !$item->isLink()) rmdir($item->getPathname());
        else unlink($item->getPathname());
    }
    rmdir($path);
}

function databaseInstanceMaterializeCodeShell(string $kind, string $source, string $target, string $folder): int
{
    if (!in_array($kind, ['egm', 'tc'], true) || !is_dir($source) || trim($folder) === '') {
        throw new InvalidArgumentException('Invalid database instance materialization request.');
    }
    if (!is_dir($target) && !mkdir($target, 0775, true) && !is_dir($target)) {
        throw new RuntimeException('Unable to create restored instance directory.');
    }
    $copied = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $item) {
        $relative = databaseInstanceMaterializerRelative($source, $item->getPathname());
        if (!databaseInstanceMaterializerShouldCopy($kind, $relative, $item->isDir())) continue;
        $destination = $target . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if ($item->isDir()) {
            if (!is_dir($destination) && !mkdir($destination, 0775, true) && !is_dir($destination)) {
                throw new RuntimeException('Unable to create restored code directory: ' . $relative);
            }
            continue;
        }
        if (!is_dir(dirname($destination)) && !mkdir(dirname($destination), 0775, true) && !is_dir(dirname($destination))) {
            throw new RuntimeException('Unable to create restored code parent: ' . $relative);
        }
        $content = file_get_contents($item->getPathname());
        if (!is_string($content)) throw new RuntimeException('Unable to read template code: ' . $relative);
        if ($item->getFilename() === '.htaccess' || preg_match('/\.(?:php|js|css)$/i', $relative) === 1) {
            $content = databaseInstanceMaterializerPatch($kind, $content, $folder);
        }
        if (file_put_contents($destination, $content, LOCK_EX) === false) {
            throw new RuntimeException('Unable to restore instance code: ' . $relative);
        }
        $copied++;
    }
    return $copied;
}

/**
 * Rebuilds missing generated code shells after a full database import. No
 * event data is written to disk; settings, periods, users, responses, logs,
 * cards and assets continue to be served from their per-instance DB tables.
 *
 * @return list<array{kind:string,code:string,directory:string,files:int}>
 */
function materializeDatabaseBackedInstances(PDO $pdo, string $projectRoot): array
{
    $projectRoot = rtrim($projectRoot, DIRECTORY_SEPARATOR);
    $restored = [];
    $groups = [
        ['kind' => 'egm', 'records' => listEgmRegistry($pdo), 'prefix' => EGM_INSTANCES_DIRECTORY . '/', 'source' => $projectRoot . '/mini apps/Event Guest Manager', 'panel' => 'EGM Panel.php'],
        ['kind' => 'tc', 'records' => listTcRegistry($pdo), 'prefix' => TC_INSTANCES_DIRECTORY . '/', 'source' => $projectRoot . '/mini apps/Task Club', 'panel' => 'TC Panel.php'],
    ];
    foreach ($groups as $group) {
        foreach ($group['records'] as $record) {
            $directory = $group['kind'] === 'egm'
                ? normalizeEgmRegistryDirectory($record['directory'] ?? '')
                : normalizeTcRegistryDirectory($record['directory'] ?? '');
            if (!str_starts_with($directory, $group['prefix'])) continue;
            $folder = basename($directory);
            $target = $projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $directory);
            if (is_file($target . DIRECTORY_SEPARATOR . $group['panel'])) continue;
            $parent = dirname($target);
            if (!is_dir($parent) && !mkdir($parent, 0775, true) && !is_dir($parent)) {
                throw new RuntimeException('Unable to create database instance parent directory.');
            }
            $staging = $parent . DIRECTORY_SEPARATOR . '.restore-code-' . $folder . '-' . bin2hex(random_bytes(5));
            try {
                $files = databaseInstanceMaterializeCodeShell($group['kind'], $group['source'], $staging, $folder);
                if (!is_file($staging . DIRECTORY_SEPARATOR . $group['panel'])) {
                    throw new RuntimeException('Restored instance code shell is incomplete: ' . $directory);
                }
                if (is_dir($target)) {
                    databaseInstanceMaterializeCodeShell($group['kind'], $group['source'], $target, $folder);
                    databaseInstanceMaterializerRemoveTree($staging);
                } elseif (!rename($staging, $target)) {
                    throw new RuntimeException('Unable to publish restored instance code: ' . $directory);
                }
                $restored[] = ['kind' => $group['kind'], 'code' => (string)$record['code'], 'directory' => $directory, 'files' => $files];
            } catch (Throwable $error) {
                databaseInstanceMaterializerRemoveTree($staging);
                throw $error;
            }
        }
    }
    return $restored;
}
