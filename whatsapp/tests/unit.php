<?php
require dirname(__DIR__).'/bootstrap.php';
use WaChat\Protocol;
$count=0;
function check($actual,$expected,string $label): void {
    global $count;
    if ($actual!==$expected) { throw new RuntimeException($label.' expected '.var_export($expected,true).' got '.var_export($actual,true)); }
    $count++;
}
check(Protocol::signature('body','sha256='.hash_hmac('sha256','body','secret'),'secret'),true,'valid signature');
check(Protocol::signature('tampered','sha256='.hash_hmac('sha256','body','secret'),'secret'),false,'tampered body');
check(Protocol::signature('body','',''),false,'empty secret');
check(Protocol::expired(1000,87399),false,'just inside window');
check(Protocol::expired(1000,87400),true,'exact expiry boundary');
check(Protocol::whatsappText(Protocol::customerHtml("<img src=x onerror=alert(1)> | 😀\nhello")),"<img src=x onerror=alert(1)> | 😀\nhello",'safe round trip');
check(strpos(Protocol::customerHtml('<script>'),'<'),false,'customer HTML escaped');
check(Protocol::whatsappText('<a href="https://example.com/a?a=1&amp;b=2">file</a><br>next'),'file (https://example.com/a?a=1&b=2)' . "\nnext",'attachment links');
check(Protocol::whatsappText('<script>secret()</script><b>Hello</b>'),'Hello','script removal');
check(count(Protocol::chunks(str_repeat('😀',8001))),3,'unicode chunks');
check(mb_strlen(Protocol::chunks(str_repeat('😀',8001))[0]),4000,'unicode chunk length');
check(Protocol::delivery('read','sent'),'read','out-of-order status');
check(Protocol::delivery('delivered','failed'),'delivered','late contradictory failure');
check(Protocol::messageText(['type'=>'image','image'=>['caption'=>'Receipt']]),"[WhatsApp image message: native media is not supported yet]\nReceipt",'media placeholder');
check(Protocol::messageText(['type'=>'button','button'=>['text'=>'Help']]),'Help','template quick reply');
$body=['object'=>'whatsapp_business_account','entry'=>[['changes'=>[['field'=>'messages','value'=>[
 'metadata'=>['phone_number_id'=>'123'],'contacts'=>[['wa_id'=>'919876543210','profile'=>['name'=>'Alice']]],
 'messages'=>[['id'=>'a','from'=>'919876543210','timestamp'=>'1000','type'=>'text','text'=>['body'=>'one']],['id'=>'b','from'=>'919876543210','timestamp'=>'1001','type'=>'text','text'=>['body'=>'two']]],
 'statuses'=>[['id'=>'c','status'=>'delivered','timestamp'=>'1002']]
]]]]]];
$events=Protocol::events($body);
check(count($events),3,'batch messages and statuses');
check($events[0]['payload']['_name'],'Alice','contact name matched');
check($events[0]['key']!==$events[1]['key'],true,'distinct message IDs');
check(Protocol::events(['object'=>'other']),[],'ignore unrelated object');
check(Protocol::delivery('sent','failed'),'failed','failure after sent');
check(Protocol::delivery('failed','sent'),'failed','late sent after failure');
check(substr(Protocol::customerName(str_repeat('😀',30)), -1) === ';',true,'name does not split entity');
check(WaChat\GraphClient::classify(200,0,'{"messages":[{"id":"wamid.1"}]}'),['state'=>'accepted','wamid'=>'wamid.1'],'Graph accepted response');
check(WaChat\GraphClient::classify(0,28,false)['state'],'uncertain','Graph timeout uncertain');
check(WaChat\GraphClient::classify(429,0,'{"error":{"code":130429}}')['state'],'retry','Graph rate-limit retry');
check(WaChat\GraphClient::classify(401,0,'{"error":{"code":190}}')['state'],'failed','Graph expired token rejected');
check(WaChat\GraphClient::classify(500,0,'proxy error')['state'],'uncertain','Graph ambiguous proxy failure');
check(WaChat\GraphClient::classify(200,0,'{}')['state'],'uncertain','Graph missing message ID');
echo "PASS: $count unit checks\n";
