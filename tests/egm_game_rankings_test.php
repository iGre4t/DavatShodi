<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/api/lib/egm-period-exports.php';
require_once dirname(__DIR__).'/api/lib/egm-game-rankings.php';
function rankCheck(bool $condition,string $message):void { if (!$condition) throw new RuntimeException($message); }
$game=['id'=>'1234567890abcdef','name'=>'بازی دیجیتال','has_levels'=>true,'gender_mode'=>'separated','levels'=>[['id'=>'a','name'=>'لیزر'],['id'=>'b','name'=>'گرید']]];
$profiles=[1=>['gender'=>'male','national_id'=>'0012345678','work_id'=>'02','phone_number'=>'09120000000'],2=>['gender'=>'female','national_id'=>'0098765432','work_id'=>'03']];
$teams=[];
foreach ([[1,1,20,5],[2,1,15,10],[3,1,1,0],[4,2,10,2],[5,2,3,0]] as [$id,$member,$first,$partial]) $teams[]=['id'=>$id,'name'=>'تیم یکسان','members'=>[['id'=>$member,'name'=>$member===1?'علی نمونه':'مینا نمونه']], 'scores'=>['a'=>['score'=>$first,'submitted_at'=>'2026-10-11T10:00:00','submitted_by'=>'ref']], 'score_attempts'=>['a'=>[['score'=>$first,'submitted_at'=>'2026-10-11T10:00:00','submitted_by'=>'ref']],'b'=>[['score'=>$partial,'submitted_at'=>'2026-10-11T10:05:00','submitted_by'=>'ref']]]];
$ranked=egmGameRankTeams($game,$teams,$profiles,1);
rankCheck(array_column($ranked,'id')===[1,2,4],'Top rank includes male ties and independent female rank');
rankCheck(array_column($ranked,'ranking_total')===[25.0,25.0,12.0],'Partial rounds included without double counting final scores');
rankCheck(array_column($ranked,'rank')===[1,1,1],'Ties use same rank');
$normal=$game;$normal['gender_mode']='normal';rankCheck(count(egmGameRankTeams($normal,$teams,$profiles,1))===2,'Normal mode combines genders');
$ranked=egmGameRankTeams($game,$teams,$profiles,3);rankCheck($ranked[2]['rank']===3,'Competition ranking skips rank after ties');
$data=[['game'=>$game,'teams'=>$ranked]];
$xlsx=egmGameRankingXlsx($data,'روز اول');$docx=egmGameRankingDocx($data,'روز اول');
foreach (['xlsx'=>$xlsx,'docx'=>$docx] as $ext=>$bytes) {
    $path=tempnam(sys_get_temp_dir(),'rank');file_put_contents($path,$bytes);$zip=new ZipArchive();rankCheck($zip->open($path)===true,'Valid '.$ext.' archive');
    for($i=0;$i<$zip->numFiles;$i++) { $entry=$zip->getNameIndex($i);if(str_ends_with($entry,'.xml')||str_ends_with($entry,'.rels')) { $dom=new DOMDocument();rankCheck($dom->loadXML($zip->getFromIndex($i)), 'Valid XML '.$entry); } }
    if($ext==='xlsx') {
        $dom=new DOMDocument();$dom->loadXML($zip->getFromName('xl/workbook.xml'));$xp=new DOMXPath($dom);$xp->registerNamespace('s','http://schemas.openxmlformats.org/spreadsheetml/2006/main');$names=[];foreach($xp->query('//s:sheet') as $sheet)$names[]=$sheet->getAttribute('name');rankCheck(count($names)===6&&count(array_unique($names))===6,'Unique sheets for duplicate team names');
        $xml=$zip->getFromName('xl/worksheets/sheet2.xml');rankCheck(str_contains($xml,'0012345678')&&str_contains($xml,'t="inlineStr"'),'Leading-zero identifiers preserved');rankCheck(str_contains($xml,'2026-10-11T10:05:00')&&str_contains($xml,'جمع مرحله'),'Round timestamps and totals present');
    }else{ $xml=$zip->getFromName('word/document.xml');rankCheck(str_contains($xml,'رتبه اول آقایان')&&str_contains($xml,'رتبه اول بانوان')&&str_contains($xml,'آقای علی نمونه')&&str_contains($xml,'خانم مینا نمونه'),'Presenter titles and member names'); }
    $zip->close();unlink($path);
}
$game['no_score_needed']=true;rankCheck(egmGameRankTeams($game,$teams,$profiles,3)===[],'Completion-only games excluded');
echo "PASS: gender ranking, unfinished/partial scores, ties, unique team sheets, text IDs, round detail and valid DOCX/XLSX packages\n";
if(in_array('--samples',$argv,true)){file_put_contents(dirname(__DIR__).'/outputs/ranking-preview.xlsx',$xlsx);file_put_contents(dirname(__DIR__).'/outputs/ranking-preview.docx',$docx);}
