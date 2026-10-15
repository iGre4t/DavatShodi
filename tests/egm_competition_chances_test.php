<?php
require_once dirname(__DIR__).'/api/lib/egm-competition-prizes.php';
function chanceCheck(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);}
$rows=[['id'=>'a','chance'=>'normal'],['id'=>'b','chance'=>'more'],['id'=>'c','chance'=>'priority']];
chanceCheck(egmCompetitionChanceWeights($rows)===[2,4,9],'Priority must have 60%, more twice normal');
$counts=['a'=>0,'b'=>0,'c'=>0];
foreach(range(1,15) as $ticket)$counts[egmCompetitionChoosePrize($rows,$ticket)['id']]++;
chanceCheck($counts===['a'=>2,'b'=>4,'c'=>9],'Random selection tickets do not match probability');
$rows[]=['id'=>'d','chance'=>'priority'];$weights=egmCompetitionChanceWeights($rows);
chanceCheck(($weights[2]+$weights[3])/array_sum($weights)===0.6,'Multiple priorities must share 60%');
chanceCheck(egmCompetitionChanceWeights([['chance'=>'priority'],['chance'=>'priority']])===[1,1],'Only priorities must remain selectable');
chanceCheck(egmCompetitionChanceWeights([['last'=>100],['last'=>1]])===[1,1],'Legacy quantities should not weight chance');
chanceCheck(egmCompetitionChanceWeights([['chance'=>'normal'],['chance'=>'more']])===[1,2],'More must have double normal weight without priority');
$inventory=[['id'=>'old','name'=>'Awarded','last'=>10,'rank'=>2,'chance'=>'priority'],['id'=>'other','name'=>'Other','last'=>1,'rank'=>2,'chance'=>'normal'],['id'=>'wrong-rank','name'=>'Excluded','last'=>50,'rank'=>1,'chance'=>'priority']];
$eligible=egmCompetitionEligiblePrizes($inventory,2,[['prizeId'=>'old','prizeName'=>'Awarded']]);
chanceCheck(array_column($eligible,'id')===['other']&&egmCompetitionChoosePrize($eligible)['id']==='other','Priority bypassed rank/name exclusion');
echo "PASS: exact 60% priority group, double normal weight, shared priorities, fallback and rank/name exclusions\n";
