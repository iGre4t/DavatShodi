<?php
declare(strict_types=1);
$keyPath = sys_get_temp_dir() . '/egm-facilitator-test-' . bin2hex(random_bytes(8)) . '.key';
putenv('TC_PASSWORD_VAULT_KEY_FILE=' . $keyPath);
require_once dirname(__DIR__) . '/api/lib/egm-facilitators.php';

function check(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function invalid(callable $callback): void {
    try { $callback(); } catch (InvalidArgumentException $expected) { return; }
    throw new RuntimeException('Invalid facilitator input accepted.');
}
// Exercise the real persistence functions with an isolated PDO test double.
class FacilitatorTestStatement extends PDOStatement {
    private FacilitatorTestPdo $database; private string $sql; private array $params = [];
    public function __construct(FacilitatorTestPdo $database, string $sql) { $this->database=$database; $this->sql=$sql; }
    public function execute(?array $params = null): bool {
        $this->params=$params ?? [];
        if (str_starts_with($this->sql,'INSERT INTO')) {
            preg_match('/`(egm_\d+)`/', $this->sql, $match);
            $this->database->data[$match[1]][$this->params[':data_key']]=$this->params[':payload'];
        }
        return true;
    }
    public function fetchColumn(int $column = 0): mixed {
        if (str_contains($this->sql,'GET_LOCK') || str_contains($this->sql,'RELEASE_LOCK')) return 1;
        preg_match('/`(egm_\d+)`/', $this->sql, $match);
        return $this->database->data[$match[1]][$this->params[':data_key']] ?? false;
    }
}
class FacilitatorTestPdo extends PDO {
    public array $data=[];
    public function __construct() {}
    public function prepare(string $query, array $options = []): PDOStatement|false { return new FacilitatorTestStatement($this,$query); }
}
try {
    $pdo=new FacilitatorTestPdo(); $cache=&egmInstanceEnsureCache();
    foreach (['12345','67890'] as $code) $cache[spl_object_id($pdo).':'.$code]=true;
    $input=['name'=>'علی رضایی','username'=>'Ref01','password'=>'001234'];
    $public=egmFacilitatorsMutate($pdo,'12345',fn($rows)=>egmFacilitatorsApplySave($rows,$input));
    $rows=egmFacilitatorsRead($pdo,'12345'); $first=$rows[0];
    check(count($public)===1 && !isset($public[0]['password_hash'],$public[0]['password_encrypted']), 'List exposes credentials');
    check($first['password_hash']!==$input['password'] && tcPasswordVaultDecrypt($first['password_encrypted'])==='001234', 'Encrypted reveal failed');
    check(egmFacilitatorsAuthenticateRows($rows,'ref01','001234')!==null,'Facilitator login failed');
    check(egmFacilitatorsAuthenticateRows($rows,'ref01','wrong')===null,'Wrong password accepted');
    check(egmFacilitatorsAuthenticateRows(egmFacilitatorsRead($pdo,'67890'),'ref01','001234')===null,'Account leaked across events');
    $session=egmFacilitatorsSessionUser($rows,$first['id'],$first['auth_version']);
    check($session['code']==='facilitator:'.$first['id'] && $session['fullname']==='علی رضایی','Session actor invalid');
    egmFacilitatorsMutate($pdo,'12345',fn($rows)=>egmFacilitatorsApplySave($rows,['name'=>'نام تازه','username'=>'Ref01','password'=>''],$first['id']));
    $rows=egmFacilitatorsRead($pdo,'12345');
    check($rows[0]['password_hash']===$first['password_hash'] && $rows[0]['auth_version']===$first['auth_version'],'Name edit replaced credentials');
    egmFacilitatorsMutate($pdo,'12345',fn($rows)=>egmFacilitatorsApplySave($rows,['name'=>'نام تازه','username'=>'Ref01','password'=>'new-pass'],$first['id']));
    $rows=egmFacilitatorsRead($pdo,'12345');
    check(egmFacilitatorsSessionUser($rows,$first['id'],$first['auth_version'])===null,'Password change did not revoke session');
    check(egmFacilitatorsAuthenticateRows($rows,'ref01','001234')===null,'Old password accepted');
    check(egmFacilitatorsAuthenticateRows($rows,'ref01','new-pass')!==null,'New password rejected');
    $before=$pdo->data;
    invalid(fn()=>egmFacilitatorsMutate($pdo,'12345',fn($rows)=>egmFacilitatorsApplyImport($rows,[$input],false)));
    invalid(fn()=>egmFacilitatorsMutate($pdo,'12345',fn($rows)=>egmFacilitatorsApplyImport($rows,[$input,$input],true)));
    invalid(fn()=>egmFacilitatorsMutate($pdo,'12345',fn($rows)=>egmFacilitatorsApplyImport($rows,[['name'=>'Valid','username'=>'new','password'=>'pass'],['name'=>'Invalid','username'=>'bad space','password'=>'pass']],false)));
    check($pdo->data===$before,'Failed import partially saved');
    $public=egmFacilitatorsMutate($pdo,'12345',fn($rows)=>egmFacilitatorsApplyImport($rows,[$input,['name'=>'زهرا','username'=>'0002','password'=>'  pass  ']],true));
    $rows=egmFacilitatorsRead($pdo,'12345');
    check(count($public)===2 && $rows[0]['id']===$first['id'],'Import update duplicated account');
    check(egmFacilitatorsAuthenticateRows($rows,'0002','  pass  ')!==null,'Password whitespace changed');
    invalid(fn()=>egmFacilitatorsApplySave($rows,['name'=>'Duplicate','username'=>'REF01','password'=>'p']));
    foreach (['',str_repeat('p',73),"a\0b",'   '] as $password) invalid(fn()=>egmFacilitatorValidate(['name'=>'Test','username'=>'new','password'=>$password]));
    $latest=$rows[0];
    egmFacilitatorsMutate($pdo,'12345',fn($rows)=>array_values(array_filter($rows,fn($row)=>$row['id']!==$first['id'])));
    check(egmFacilitatorsSessionUser(egmFacilitatorsRead($pdo,'12345'),$latest['id'],$latest['auth_version'])===null,'Deleted account still has access');
    echo "Facilitator persistence, event isolation, password encryption/login, credential revocation, edits and atomic imports passed (PDO test double).\n";
} finally { if (is_file($keyPath)) unlink($keyPath); }
