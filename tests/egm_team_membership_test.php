<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/api/lib/egm-refmonitor-teams.php';
function membershipCheck(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function membershipRejected(callable $work):void{try{$work();}catch(InvalidArgumentException $error){membershipCheck(str_contains($error->getMessage(),'تیم دیگری'),'Unexpected validation');return;}throw new RuntimeException('Duplicate membership accepted');}
class MembershipPdo extends PDO{
    public array $teams=[]; public array $data=[];public array $profiles=[];public int $lastId=0;public int $lockDepth=0;
    public function __construct(){}
    public function exec(string $statement):int|false{return 0;}
    public function lastInsertId(?string $name=null):string|false{return (string)$this->lastId;}
    public function prepare(string $query,array $options=[]):PDOStatement|false{return new MembershipStatement($this,$query);}
    public function query(string $query,?int $fetchMode=null,mixed ...$args):PDOStatement|false{return new MembershipStatement($this,$query);}
}
class MembershipStatement extends PDOStatement{
    private array $params=[];
    public function __construct(private MembershipPdo $pdo,private string $sql){}
    public function execute(?array $params=null):bool{
        $this->params=$params??[];
        if(str_contains($this->sql,'GET_LOCK'))$this->pdo->lockDepth++;
        if(str_contains($this->sql,'RELEASE_LOCK'))$this->pdo->lockDepth--;
        if(str_starts_with($this->sql,'INSERT INTO')&&str_contains($this->sql,'(`user_id`, `payload`)')){
            membershipCheck($this->pdo->lockDepth===1,'Team inserted without lock');
            $id=++$this->pdo->lastId;$this->pdo->teams[$id]=['id'=>$id,'created_at'=>'2026-10-07 10:00:00','payload'=>$this->params[':payload']];
        }
        if(str_starts_with($this->sql,'UPDATE')&&isset($this->params[':payload'])){
            membershipCheck($this->pdo->lockDepth===1,'Members edited without lock');
            $this->pdo->teams[$this->params[':id']]['payload']=$this->params[':payload'];
        }
        return true;
    }
    public function fetchColumn(int $column=0):mixed{
        if(str_contains($this->sql,'GET_LOCK')||str_contains($this->sql,'RELEASE_LOCK')||str_contains($this->sql,'information_schema'))return 1;
        if(str_contains($this->sql,'`periods`'))return json_encode([['tagCode'=>'001','taskType'=>'period','title'=>'بازه']]);
        return isset($this->pdo->data[$this->params[':data_key']??''])?json_encode($this->pdo->data[$this->params[':data_key']]):false;
    }
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $orientation=PDO::FETCH_ORI_NEXT,int $offset=0):mixed{return $this->pdo->teams[$this->params[':id']??0]??false;}
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args):array{
        if(str_contains($this->sql,'WHERE `user_id` IS NULL')){
            return $mode===PDO::FETCH_COLUMN?array_column($this->pdo->teams,'payload'):array_values($this->pdo->teams);
        }
        $rows=$this->pdo->profiles;
        if(str_contains($this->sql,'NOT IN (')){
            preg_match('/NOT IN \(([^)]+)\)/',$this->sql,$match);$occupied=array_map('intval',explode(',',$match[1]));
            $rows=array_filter($rows,fn($row)=>!in_array($row['id'],$occupied,true));
        }
        if(str_contains($this->sql,'u.`id` IN (')){
            $ids=array_slice(array_values($this->params),1);$rows=array_filter($rows,fn($row)=>in_array($row['id'],$ids,true));
        }
        return array_values($rows);
    }
}
$pdo=new MembershipPdo();$cache=&egmInstanceEnsureCache();$cache[spl_object_id($pdo).':12345']=true;
$gameId='1234567890abcdef';$pdo->data[EGM_GAMES_KEY]=['games'=>[['id'=>$gameId,'name'=>'بازی','has_levels'=>false,'min_players'=>1,'max_players'=>10]],'enabled'=>['001'=>[$gameId]]];
$pdo->profiles=[['id'=>1,'first_name'=>'علی','last_name'=>'رضایی','work_id'=>'001','national_id'=>'0012345678','gender'=>'male'],['id'=>2,'first_name'=>'رضا','last_name'=>'رضایی','work_id'=>'002','national_id'=>'0022345678','gender'=>'male']];
$staleSearch=egmRefMonitorSearchInvitees($pdo,'12345','001','رضایی',$gameId);
membershipCheck(count($staleSearch)===2,'Available people missing');
$team=egmRefMonitorCreateTeam($pdo,'12345','001',$gameId,'تیم اول',[1,2],'');
membershipCheck(egmRefMonitorSearchInvitees($pdo,'12345','001','رضایی',$gameId)===[],'Unstarted members still searchable');
membershipRejected(fn()=>egmRefMonitorCreateTeam($pdo,'12345','001',$gameId,'تیم دوم',[$staleSearch[0]['id']],''));
$table=egmGamesTableName('12345','001',$gameId);
foreach([['started_at'=>'2026-10-07 10:00:00'],['started_at'=>'2026-10-07 10:00:00','ended_at'=>'2026-10-07 11:00:00']] as $state){
    $payload=json_decode($pdo->teams[$team['id']]['payload'],true);$pdo->teams[$team['id']]['payload']=json_encode(array_replace($payload,$state));
    membershipCheck(egmRefMonitorSearchInvitees($pdo,'12345','001','رضایی',$gameId)===[],'Started/ended member still searchable');
    membershipRejected(fn()=>egmRefMonitorWithLock($pdo,'12345',$gameId,fn()=>egmRefMonitorAssertMembersAvailable($pdo,$table,[['id'=>1]])));
}
$payload=json_decode($pdo->teams[$team['id']]['payload'],true);unset($payload['started_at'],$payload['ended_at']);$pdo->teams[$team['id']]['payload']=json_encode($payload);
egmRefMonitorUpdateMembers($pdo,'12345','001',$gameId,$team['id'],[2]);
$search=egmRefMonitorSearchInvitees($pdo,'12345','001','رضایی',$gameId);
membershipCheck(array_column($search,'id')===[1],'Removed person not released or retained person leaked');
$second=egmRefMonitorCreateTeam($pdo,'12345','001',$gameId,'تیم دوم',[1],'');
membershipRejected(fn()=>egmRefMonitorUpdateMembers($pdo,'12345','001',$gameId,$team['id'],[1,2]));
membershipCheck(count($pdo->teams)===2&&$pdo->lockDepth===0,'Failed save inserted a team or leaked lock');
echo "Immediate team reservation, hidden search results, stale creation/member edits rejected, started/ended reservation, kick/rejoin and lock cleanup passed with a PDO fixture.\n";
