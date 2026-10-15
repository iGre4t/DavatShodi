<?php
require_once dirname(__DIR__).'/api/lib/egm-competition-prizes.php';
function uniquePrizeCheck(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);}
$inventory=[
 ['id'=>'a','name'=>'GooglePlay Gift Card','last'=>1,'rank'=>1],
 ['id'=>'b','name'=>' googleplay  gift card ','last'=>100,'rank'=>2],
 ['id'=>'c','name'=>'GooglePlay Gift Card','last'=>20,'rank'=>1],
 ['id'=>'d','name'=>'Other Gift','last'=>1,'rank'=>1],
 ['id'=>'e','name'=>'Empty','last'=>0,'rank'=>1],
 ['id'=>'f','name'=>'Fake','last'=>5,'rank'=>1,'isFake'=>true],
];
$confirmed=[['prizeId'=>'a','prizeName'=>'GooglePlay Gift Card']];
uniquePrizeCheck(array_column(egmCompetitionAvailablePrizes($inventory,$confirmed,null,false,true),'id')===['b','c','d'],'Name switch did not allow other matching-name IDs');
uniquePrizeCheck(array_column(egmCompetitionAvailablePrizes($inventory,$confirmed,null,true,false),'id')===['a','d'],'Same-prize switch did not independently allow its own stocked ID');
uniquePrizeCheck(array_column(egmCompetitionAvailablePrizes($inventory,$confirmed,1,false,false),'id')===['a','c','d'],'Both switches ignored rank/stock/fake restrictions');
$public=egmCompetitionStatePublic(['confirmed'=>$confirmed],[1,1,1],$inventory,true,false,false,false);
uniquePrizeCheck($public['previewPrizes']===array_column(egmCompetitionAvailablePrizes($inventory,$confirmed,null,false,false),'name'),'Preview and server selection policies differed');
uniquePrizeCheck(array_column(egmCompetitionAvailablePrizes($inventory,$confirmed),'id')===['d'],'Same-name rows were eligible across ranks');
uniquePrizeCheck(count(egmCompetitionAvailablePrizes($inventory,[]))===4,'New/reset period must allow prizes again');
uniquePrizeCheck(array_column(egmCompetitionEligiblePrizes($inventory,1),'id')===['a','c','d'],'Stock quantity changed selection weight');
$inventory[0]['name']='Renamed';$inventory[]=['id'=>'g','name'=>'Renamed','last'=>5,'rank'=>1];
uniquePrizeCheck(array_column(egmCompetitionAvailablePrizes($inventory,$confirmed),'id')===['d'],'Renaming an awarded prize allowed repetition');
uniquePrizeCheck(egmCompetitionPrizeNameKey(' كارت  هديه ')===egmCompetitionPrizeNameKey('کارت هدیه'),'Arabic/Persian variants were treated differently');
echo "PASS: same names across IDs/ranks excluded, reset/new periods restore eligibility, fake/empty stock excluded, renamed IDs protected, quantity not weighted\n";
