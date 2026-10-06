<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/api/lib/egm-refmonitor-teams.php';
egmRefPushRuntime();
$keys=egmRefPushCreateKeys();
\Minishlink\WebPush\VAPID::validate($keys+['subject'=>'https://example.test']);
$endpoint='https://fcm.googleapis.com/fcm/send/test';
$encode=fn($bytes)=>rtrim(strtr(base64_encode($bytes),'+/','-_'),'=');
$subscription=['endpoint'=>$endpoint,'keys'=>['p256dh'=>$keys['publicKey'],'auth'=>$encode(random_bytes(16))]];
$validated=egmRefPushSubscription($subscription);
if($validated['endpoint']!==$endpoint)throw new RuntimeException('Subscription changed');
foreach(['http://fcm.googleapis.com/test','https://127.0.0.1/test','https://evil.test/test','https://user@fcm.googleapis.com/test','https://fcm.googleapis.com:443/test']as $bad){
    try{egmRefPushSubscription(array_replace($subscription,['endpoint'=>$bad]));throw new RuntimeException('Unsafe endpoint accepted');}catch(InvalidArgumentException $expected){}
}
$mock=new \GuzzleHttp\Handler\MockHandler([new \GuzzleHttp\Psr7\Response(201)]);
$history=[];$stack=\GuzzleHttp\HandlerStack::create($mock);$stack->push(\GuzzleHttp\Middleware::history($history));
$client=new \GuzzleHttp\Client(['handler'=>$stack]);
$push=new \Minishlink\WebPush\WebPush([],['TTL'=>180,'urgency'=>'high'],$client);
$report=$push->sendOneNotification(\Minishlink\WebPush\Subscription::create($validated),json_encode(['title'=>'اتاق آماده شد','body'=>'آفتاب']),[],['VAPID'=>$keys+['subject'=>'https://example.test']]);
if(!$report->isSuccess()||count($history)!==1)throw new RuntimeException('Push delivery failed');
$request=$history[0]['request'];
if($request->getHeaderLine('Content-Encoding')!=='aes128gcm'||!str_starts_with($request->getHeaderLine('Authorization'),'vapid ')||str_contains((string)$request->getBody(),'آفتاب'))throw new RuntimeException('Push was not encrypted/authenticated');
echo "VAPID keys, subscription validation, endpoint restrictions and encrypted push transport passed with mocked HTTP.\n";
