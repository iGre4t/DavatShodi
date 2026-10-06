<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/api/lib/egm-games.php';
function verifySetting(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function rejectedSetting(callable $work): void { try { $work(); } catch (InvalidArgumentException $error) { return; } throw new RuntimeException('Invalid setting accepted'); }
class SettingsFixtureStatement extends PDOStatement {
    private array $params = [];
    public function __construct(private SettingsFixturePdo $pdo, private string $sql) {}
    public function execute(?array $params = null): bool {
        $this->params = $params ?? [];
        if (str_starts_with($this->sql, 'INSERT INTO')) $this->pdo->data[$this->params[':data_key']] = $this->params[':payload'];
        return true;
    }
    public function fetchColumn(int $column = 0): mixed {
        if (str_contains($this->sql, 'GET_LOCK') || str_contains($this->sql, 'RELEASE_LOCK')) return 1;
        return $this->pdo->data[$this->params[':data_key'] ?? ''] ?? false;
    }
}
class SettingsFixturePdo extends PDO {
    public array $data = [];
    public function __construct() {}
    public function prepare(string $query, array $options = []): PDOStatement|false { return new SettingsFixtureStatement($this, $query); }
}
$pdo = new SettingsFixturePdo(); $cache = &egmInstanceEnsureCache(); $cache[spl_object_id($pdo) . ':12345'] = true;
$context = ['pdo'=>$pdo,'code'=>'12345']; $id = '1234567890abcdef';
egmGamesWrite($context, ['games'=>[['id'=>$id,'name'=>'تست','has_levels'=>false,'rooms'=>[],'levels'=>[]]],'enabled'=>[]]);
foreach (['save_no_score_mode'=>'enabled','save_auto_mode'=>'enabled','save_cover_color_requirement'=>'required','save_mode'=>'has_levels','set_enabled'=>'enabled'] as $action=>$field) {
    foreach (['1'=>true,'0'=>false] as $value=>$expected) {
        $_POST = ['action'=>$action,'csrf'=>'test',$field=>(string)$value];
        verifySetting(egmGamesRequestInput('POST')[$field] === $expected, 'Form boolean lost');
    }
    $_POST = ['action'=>$action,$field=>'true']; rejectedSetting(fn()=>egmGamesRequestInput('POST'));
}
$_POST = ['payload'=>json_encode(['action'=>'save_no_score_mode','enabled'=>true])];
verifySetting(egmGamesRequestInput('POST')['enabled'] === true, 'Legacy JSON transport broken');
$_POST = ['payload'=>'invalid']; rejectedSetting(fn()=>egmGamesRequestInput('POST'));
egmGamesSaveNoScoreMode($context,$id,true); verifySetting(egmGamesState($context)['games'][0]['no_score_needed'], 'No-score save failed');
egmGamesSaveNoScoreMode($context,$id,false); verifySetting(!egmGamesState($context)['games'][0]['no_score_needed'], 'Score save failed');
egmGamesSaveCoverColorRequirement($context,$id,true); verifySetting(egmGamesState($context)['games'][0]['require_cover_color'], 'Cover save failed');
egmGamesSaveLimits($context,$id,'2','8'); verifySetting(egmGamesState($context)['games'][0]['max_players'] === 8, 'Limits save failed');
$before=$pdo->data; rejectedSetting(fn()=>egmGamesSaveLimits($context,$id,9,2)); verifySetting($pdo->data === $before, 'Invalid limits changed state');
rejectedSetting(fn()=>egmGamesSaveAutoMode($context,$id,true));
egmGamesSaveRoom($context,$id,'game_total','','اتاق','both');
egmGamesSaveAutoMode($context,$id,true); verifySetting(egmGamesState($context)['games'][0]['auto_room_manager'], 'Auto mode failed');
rejectedSetting(fn()=>egmGamesSaveMode($context,$id,true));
egmGamesSaveAutoMode($context,$id,false);
rejectedSetting(fn()=>egmGamesSaveGenderMode($context,$id,'separated'));
$roomId=egmGamesState($context)['games'][0]['rooms'][0]['id'];
egmGamesSaveRoom($context,$id,'game_total',$roomId,'اتاق','male'); egmGamesSaveGenderMode($context,$id,'separated');
verifySetting(egmGamesState($context)['games'][0]['gender_mode'] === 'separated', 'Gender save failed');
egmGamesSaveGenderMode($context,$id,'normal'); egmGamesSaveMode($context,$id,true); egmGamesSaveLevel($context,$id,'','مرحله');
$levelId=egmGamesState($context)['games'][0]['levels'][0]['id']; egmGamesSaveRoom($context,$id,$levelId,'','اتاق مرحله','both');
egmGamesSaveAutoMode($context,$id,true); verifySetting(egmGamesState($context)['games'][0]['has_levels'], 'Level mode failed');
echo "Game form booleans, legacy JSON, no-score/score, cover, limits, room/level, gender and auto-mode persistence/validation passed with a PDO fixture.\n";
