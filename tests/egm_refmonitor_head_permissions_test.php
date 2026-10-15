<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/api/lib/egm-refmonitor-manager.php';
$head=['event_role'=>'head_referee'];
foreach (['games','teams','team'] as $method) egmRefManagerAssert($head,['method'=>$method]);
egmRefManagerAssert($head,['method'=>'room','enabled'=>false]);
$blocked=['settings','save_level','delete_level','search','details','members','disband','start','reopen','end','score','unknown'];
foreach ($blocked as $method) {
    try {egmRefManagerAssert($head,['method'=>$method]);}
    catch (InvalidArgumentException $expected) {continue;}
    throw new RuntimeException('Head mutation accepted: '.$method);
}
foreach ([true,1,0,'false',null] as $enabled) {
    try {egmRefManagerAssert($head,['method'=>'room','enabled'=>$enabled]);}
    catch (InvalidArgumentException $expected) {continue;}
    throw new RuntimeException('Head room enable or malformed value accepted');
}
try {egmRefManagerAssert(['event_role'=>'referee'],['method'=>'games']);throw new RuntimeException('Referee management accepted');}
catch (InvalidArgumentException $expected) {}
foreach ($blocked as $method) egmRefManagerAssert(['event_role'=>'system_manager'],['method'=>$method]);
echo "PASS: Head read-only teams and disable-only rooms; manager unchanged; referee blocked.\n";
