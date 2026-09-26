<?php
declare(strict_types=1);

$source = file_get_contents(dirname(__DIR__) . '/api/lib/tc-instance-storage.php');
foreach (['normalizeTcInstanceCode', 'tcInstanceTableNames', 'tcInstanceEnsureCache', 'dropTcInstanceTables'] as $name) {
    $pattern = '/function\s+&?' . $name . '\(/';
    if (!preg_match($pattern, $source, $match, PREG_OFFSET_CAPTURE)) throw new RuntimeException('Missing function: ' . $name);
    $start = $match[0][1];
    $end = strpos($source, "\n}\n", $start);
    if ($end === false) $end = strpos($source, "\r\n}\r\n", $start);
    if ($end === false) throw new RuntimeException('Missing function end.');
    eval(substr($source, $start, $end - $start + (str_contains(substr($source, $end, 3), "\r") ? 3 : 2)));
}
function tcInstanceTableExists(PDO $pdo, string $table): bool { return false; }
function loadConfig(string $path): array { return []; }
function connectActivityLogDatabase(array $config): ?PDO { return null; }

final class DropOrderDatabase extends PDO
{
    public array $dropped = [];
    public function __construct() {}
    public function exec(string $statement): int|false
    {
        preg_match('/DROP TABLE IF EXISTS `([^`]+)`/', $statement, $matches);
        $table = $matches[1] ?? '';
        if ($table === 'tc_1234_users' && !in_array('tc_1234_shared_answers_quiz_results', $this->dropped, true)) {
            throw new RuntimeException('Participant table still has a shared quiz foreign key.');
        }
        $this->dropped[] = $table;
        return 0;
    }
}
$pdo = new DropOrderDatabase();
dropTcInstanceTables($pdo, '1234');
$expected = array_values(tcInstanceTableNames('1234'));
sort($expected);
$actual = $pdo->dropped;
sort($actual);
if ($actual !== $expected) throw new RuntimeException('Deletion must drop every instance table.');
echo "Task Club deletion table coverage and ordering tests passed.\n";
