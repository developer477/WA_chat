<?php
// Creates its own randomly named database. Never point this at production.
require dirname(__DIR__).'/bootstrap.php';
use WaChat\{Database,Bridge,Webhook,Preflight,Sender};
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$socket=getenv('WA_TEST_SOCKET') ?: ($argv[1] ?? '');
if (!$socket) { fwrite(STDERR,"Set WA_TEST_SOCKET to an isolated MariaDB Unix socket.\n"); exit(2); }
$link=new mysqli('localhost',getenv('WA_TEST_USER') ?: 'root',getenv('WA_TEST_PASSWORD') ?: '', '',0,$socket);
$name='wa_chat_test_'.bin2hex(random_bytes(5));
$link->query("CREATE DATABASE `$name` CHARACTER SET utf8mb4");
$link->select_db($name);
function loadSql(mysqli $link,string $file): void {
    $sql=file_get_contents($file);
    if (in_array('--innodb',$GLOBALS['argv'],true)) { $sql=str_replace('ENGINE=MyISAM','ENGINE=InnoDB',$sql); }
    $link->multi_query($sql);
    do { if ($r=$link->store_result()) { $r->free(); } } while ($link->more_results() && $link->next_result());
}
$count=0;
function eq($actual,$expected,string $label): void {
    global $count;
    if ($actual!==$expected) { throw new RuntimeException($label.' expected '.var_export($expected,true).' got '.var_export($actual,true)); }
    $count++;
}
class FakeSender implements Sender {
    public array $calls=[];
    public string $state='accepted';
    public function send(array $account,string $recipient,string $body,int $outboxId): array {
        $this->calls[]=[$account['phone_number_id'],$recipient,$body,$outboxId];
        return ['state'=>$this->state,'wamid'=>$this->state==='accepted'?'wamid.out.'.$outboxId:null,'error'=>$this->state==='accepted'?null:'test_'.$this->state];
    }
}
function envelope(array $messages=[],array $statuses=[],string $phone='773505685855835'): string {
    return json_encode(['object'=>'whatsapp_business_account','entry'=>[['changes'=>[['field'=>'messages','value'=>[
        'metadata'=>['phone_number_id'=>$phone],'contacts'=>[['wa_id'=>'919876543210','profile'=>['name'=>"Alice O'Neil"]]],
        'messages'=>$messages,'statuses'=>$statuses]]]]]],JSON_THROW_ON_ERROR);
}
function message(string $id,string $text,int $offset=0): array {
    return ['id'=>$id,'from'=>'919876543210','timestamp'=>(string)(time()+$offset),'type'=>'text','text'=>['body'=>$text]];
}
function receive(Webhook $webhook,string $body): void {
    $webhook->receive($body,'sha256='.hash_hmac('sha256',$body,str_repeat('a',32)));
}
function heartbeatAt(Bridge $bridge,int $last): void {
    $property=new ReflectionProperty(Bridge::class,'lastHeartbeat');
    $property->setValue($bridge,$last);
}
try {
    loadSql($link,__DIR__.'/schema.sql'); loadSql($link,dirname(__DIR__).'/sql/001_bridge.sql');
    loadSql($link,dirname(__DIR__).'/sql/002_reply_cursor.sql');
    // Exercise the upgrade with an existing retained event, then repeat it.
    $link->query("INSERT INTO wa_inbox (event_key,phone_number_id,kind,payload,received_at,state) VALUES
        ('upgrade','773505685855835','message','{\"from\":\"919876543210\",\"timestamp\":\"1000\"}',1000,'done')");
    loadSql($link,dirname(__DIR__).'/sql/003_maintenance.sql');
    loadSql($link,dirname(__DIR__).'/sql/003_maintenance.sql');
    $backfill=$link->query("SELECT sender,message_timestamp FROM wa_inbox WHERE event_key='upgrade'")->fetch_assoc();
    eq($backfill['sender'],'919876543210','upgrade backfills sender');
    eq((int)$backfill['message_timestamp'],1000,'upgrade backfills customer timestamp');
    $link->query('TRUNCATE wa_inbox');
    $db=new Database($link); $config=require dirname(__DIR__).'/config.example.php';
    eq(count(Preflight::check($db,$config)),2,'two numbers share group');
    $sender=new FakeSender(); $bridge=new Bridge($db,$sender,$config); $webhook=new Webhook($db);
    eq($webhook->verify('test-verification'),true,'GET verify token');
    eq($webhook->verify('wrong'),false,'wrong token');
    $body=envelope([message('in.1',"Hello <script>alert(1)</script> 😀")]);
    try { $webhook->receive($body,'bad'); throw new RuntimeException('signature accepted'); } catch (UnexpectedValueException $e) { $count++; }
    receive($webhook,$body); receive($webhook,$body);
    eq((int)$db->one('SELECT COUNT(*) AS n FROM wa_inbox')['n'],1,'duplicate webhook dedup');
    $bridge->tick();
    $session=$db->one('SELECT * FROM wa_sessions ORDER BY id LIMIT 1');
    eq($session['state'],'active','provisioned');
    eq($db->one('SELECT state FROM wa_inbox')['state'],'waiting','message held while waiting');
    eq((int)$db->one('SELECT COUNT(*) AS n FROM vicidial_chat_log WHERE chat_level=0')['n'],0,'no rejected/lost waiting message');
    $chat=$session['chat_id'];
    $db->run("UPDATE vicidial_live_chats SET status='LIVE',chat_creator='agent1' WHERE chat_id=?",[$chat]);
    $db->run('UPDATE wa_inbox SET next_attempt=0'); $bridge->tick();
    eq($db->one('SELECT state FROM wa_inbox')['state'],'done','waiting message delivered');
    eq((int)$db->one('SELECT COUNT(*) AS n FROM vicidial_chat_log WHERE chat_level=0')['n'],1,'one customer message');
    $stored=$db->one('SELECT message FROM vicidial_chat_log WHERE chat_level=0')['message'];
    eq(strpos($stored,'<script>'),false,'customer HTML not executable');
    eq(strpos($stored,'&#128512;')!==false,true,'emoji retained in legacy utf8');
    receive($webhook,$body); $bridge->tick();
    eq((int)$db->one('SELECT COUNT(*) AS n FROM vicidial_chat_log WHERE chat_level=0')['n'],1,'no duplicate native message');
    foreach ([['agent1',0,'Hello<br>there'],['agent1',1,'PRIVATE'],[$session['member'],0,'ECHO'],['system',0,'SYSTEM']] as $row) {
        $db->insert('vicidial_chat_log',['chat_id'=>$chat,'message'=>$row[2],'message_time'=>date('Y-m-d H:i:s'),'poster'=>$row[0],'chat_member_name'=>'Name','chat_level'=>$row[1]]);
    }
    $bridge->tick(); $bridge->tick();
    eq(count($sender->calls),1,'only public agent reply sent once');
    eq($sender->calls[0][2],"Hello\nthere",'HTML converted to text');
    receive($webhook,envelope([], [['id'=>'wamid.out.1','status'=>'read','recipient_id'=>'919876543210','timestamp'=>(string)time()]]));
    receive($webhook,envelope([], [['id'=>'wamid.out.1','status'=>'sent','recipient_id'=>'919876543210','timestamp'=>(string)time()]]));
    $bridge->tick(); eq($db->one('SELECT delivery_status FROM wa_outbox WHERE id=1')['delivery_status'],'read','status monotonic');
    // Agent reply transferred to another agent must still be relayed.
    $db->run("UPDATE vicidial_live_chats SET chat_creator='agent2',group_id='TRANSFERRED' WHERE chat_id=?",[$chat]);
    $db->insert('vicidial_chat_log',['chat_id'=>$chat,'message'=>'Transferred reply','message_time'=>date('Y-m-d H:i:s'),'poster'=>'agent2','chat_member_name'=>'Agent Two','chat_level'=>0]);
    $sender->state='uncertain'; $bridge->tick();
    eq($db->one('SELECT state FROM wa_outbox WHERE id=2')['state'],'uncertain','ambiguous send held');
    eq((int)$db->one("SELECT COUNT(*) AS n FROM vicidial_chat_log WHERE chat_id=? AND chat_level=1 AND message LIKE '%reply #2 is uncertain%'",[$chat])['n'],1,'agent gets private uncertain notice');
    $sender->state='accepted'; $bridge->tick(); eq(count($sender->calls),2,'no blind resend');
    receive($webhook,envelope([], [['id'=>'wamid.late','status'=>'delivered','recipient_id'=>'919876543210','biz_opaque_callback_data'=>'wa:2','timestamp'=>(string)time()]]));
    $bridge->tick(); eq($db->one('SELECT state FROM wa_outbox WHERE id=2')['state'],'accepted','late callback resolves uncertain');
    // Different destination business number is a different customer session, same group/credentials.
    receive($webhook,envelope([message('second.1','Second number')],[],'773505685855836')); $bridge->tick();
    eq((int)$db->one('SELECT COUNT(*) AS n FROM wa_sessions')['n'],2,'separate number sessions');
    $second=$db->one('SELECT * FROM wa_sessions WHERE id=2');
    $db->run("UPDATE vicidial_live_chats SET status='LIVE',chat_creator='agent1' WHERE chat_id=?",[$second['chat_id']]);
    $db->run('UPDATE wa_inbox SET next_attempt=0');
    $db->insert('vicidial_chat_log',['chat_id'=>$second['chat_id'],'message'=>'Number two reply','message_time'=>date('Y-m-d H:i:s'),'poster'=>'agent1','chat_member_name'=>'Agent','chat_level'=>0]);
    $bridge->tick(); eq(end($sender->calls)[0],'773505685855836','reply from correct number');
    // Server-side blocking cannot be bypassed by a direct webhook message.
    $db->run("UPDATE vicidial_chat_participants SET user_status='blocked' WHERE chat_id=? AND vd_agent='N'",[$chat]);
    receive($webhook,envelope([message('blocked.1','Blocked')])); $bridge->tick();
    eq($db->one("SELECT state FROM wa_inbox WHERE event_key=?",[hash('sha256','773505685855835:message:blocked.1')])['state'],'blocked','blocked customer');
    // Replay a journal entry left planned after the native insertion succeeded.
    $op=$db->one("SELECT * FROM wa_vici_operations WHERE operation_key='inbox:1'");
    $db->run("UPDATE wa_vici_operations SET state='planned' WHERE operation_key='inbox:1'");
    $native=$db->nativeInsert('inbox:1','vicidial_chat_log',[], 'vicidial_chat_log_archive');
    eq($native,(int)$op['target_id'],'recover committed MyISAM insert');
    // Simulate another writer taking a reserved key during a crash.
    $values=['chat_id'=>$chat,'message'=>'Recovered after collision','message_time'=>date('Y-m-d H:i:s'),'poster'=>$session['member'],'chat_member_name'=>'Customer','chat_level'=>0];
    $taken=(int)$db->one('SELECT MAX(message_row_id) AS n FROM vicidial_chat_log')['n'];
    $db->insert('wa_vici_operations',['operation_key'=>'test:collision','target_table'=>'vicidial_chat_log','target_id'=>$taken,'row_json'=>json_encode($values),'state'=>'planned']);
    $new=$db->nativeInsert('test:collision','vicidial_chat_log',$values,'vicidial_chat_log_archive');
    eq($new>$taken,true,'reserved-key collision recovered');
    // Agent closes between polls: collect last reply from archive.
    $db->insert('vicidial_chat_log',['chat_id'=>$chat,'message'=>'Final reply','message_time'=>date('Y-m-d H:i:s'),'poster'=>'agent2','chat_member_name'=>'Agent Two','chat_level'=>0]);
    $db->run('INSERT INTO vicidial_chat_log_archive SELECT * FROM vicidial_chat_log WHERE chat_id=?',[$chat]);
    $db->run('DELETE FROM vicidial_chat_log WHERE chat_id=?',[$chat]);
    $db->run('DELETE FROM vicidial_live_chats WHERE chat_id=?',[$chat]);
    $bridge->tick(); eq(end($sender->calls)[2],'Final reply','final archived reply delivered');
    eq($db->one('SELECT state FROM wa_sessions WHERE id=1')['state'],'closed','agent close detected');
    // Maintenance substitutes for Leave chat even without new message work.
    $db->run('UPDATE wa_sessions SET last_inbound=? WHERE id=2',[time()-86400]);
    $db->insert('vicidial_chat_participants',['chat_id'=>$second['chat_id'],'chat_member'=>'agent1','chat_member_name'=>'Agent One','vd_agent'=>'Y','ping_date'=>date('Y-m-d H:i:s')]);
    heartbeatAt($bridge,0); $bridge->tick();
    eq($db->one('SELECT state FROM wa_sessions WHERE id=2')['state'],'closed','maintenance expires idle session');
    eq($db->one('SELECT status FROM vicidial_live_chats WHERE chat_id=?',[$second['chat_id']])['status'],'LIVE','idle live chat retained');
    // Replies after customer departure must not be sent.
    $before=count($sender->calls);
    $db->insert('vicidial_chat_log',['chat_id'=>$second['chat_id'],'message'=>'Too late','message_time'=>date('Y-m-d H:i:s'),'poster'=>'agent1','chat_member_name'=>'Agent','chat_level'=>0]);
    $bridge->tick();
    eq(count($sender->calls),$before,'expired outbound not sent');
    eq($db->one('SELECT state FROM wa_sessions WHERE id=2')['state'],'closed','outbound cannot reopen expired session');
    eq($db->one('SELECT status FROM vicidial_live_chats WHERE chat_id=?',[$second['chat_id']])['status'],'LIVE','agent live row preserved');
    eq($db->one('SELECT chat_id FROM vicidial_chat_archive WHERE chat_id=?',[$second['chat_id']]),null,'agent chat not archived by customer');
    eq((int)$db->one("SELECT COUNT(*) AS n FROM vicidial_chat_participants WHERE chat_id=? AND vd_agent='Y'",[$second['chat_id']])['n'],1,'agent participant preserved');
    eq((int)$db->one("SELECT COUNT(*) AS n FROM vicidial_chat_participants WHERE chat_id=? AND vd_agent='N'",[$second['chat_id']])['n'],0,'only customer participant removed');
    eq((int)$db->one("SELECT COUNT(*) AS n FROM vicidial_chat_log WHERE chat_id=? AND chat_level=1 AND message LIKE '%has left chat'",[$second['chat_id']])['n'],1,'one private leave notice');
    $bridge->close($second,'window_expired'); $bridge->tick();
    eq((int)$db->one("SELECT COUNT(*) AS n FROM vicidial_chat_log WHERE chat_id=? AND chat_level=1 AND message LIKE '%has left chat'",[$second['chat_id']])['n'],1,'departure replay does not repeat notice');
    receive($webhook,envelope([message('after.close','New conversation')],[],'773505685855836')); $bridge->tick();
    eq((int)$db->one('SELECT COUNT(*) AS n FROM wa_sessions')['n'],3,'new chat after departure');
    $third=$db->one('SELECT * FROM wa_sessions WHERE id=3');
    $db->run('UPDATE wa_sessions SET last_inbound=? WHERE id=3',[time()-86400]);
    $db->run("UPDATE wa_inbox SET message_timestamp=?,payload=JSON_SET(payload,'$.timestamp',?) WHERE session_id=3",[time()-86400,(string)(time()-86400)]);
    heartbeatAt($bridge,0); $bridge->tick();
    eq($db->one('SELECT state FROM wa_sessions WHERE id=3')['state'],'closed','maintenance expires unanswered chat');
    // Incoming message after maintenance starts a new chat.
    receive($webhook,envelope([message('after.waiting','Fresh message')],[],'773505685855836')); $bridge->tick();
    eq($db->one('SELECT status FROM vicidial_chat_archive WHERE chat_id=?',[$third['chat_id']])['status'],'DROP','maintenance performs waiting DROP');
    eq($db->one('SELECT chat_id FROM vicidial_live_chats WHERE chat_id=?',[$third['chat_id']]),null,'unclaimed live row removed');
    eq($db->one('SELECT state FROM wa_inbox WHERE session_id=? ORDER BY id LIMIT 1',[$third['id']])['state'],'expired','old buffered message expired');
    $fresh=$db->one('SELECT * FROM wa_sessions ORDER BY id DESC LIMIT 1');
    eq($fresh['state'],'active','fresh inbound creates new session');
    eq((int)$db->one("SELECT COUNT(*) AS n FROM vicidial_chat_log WHERE chat_id=? AND chat_level=1 AND message LIKE '%Receiving number: +919999999999'",[$fresh['chat_id']])['n'],1,'receiving number information once on new chat');
    // Drop the test's fresh waiting chat so the ordering scenario starts cleanly.
    $bridge->close($fresh,'window_expired');
    // Startup recovery must not resubmit in-flight operations.
    $db->run("UPDATE wa_outbox SET state='sending' WHERE id=1"); $bridge->recover();
    eq($db->one('SELECT state FROM wa_outbox WHERE id=1')['state'],'uncertain','restart send recovery');
    // A fresh buffered message cannot overtake its predecessor when an agent accepts.
    receive($webhook,envelope([message('ordered.1','First buffered')],[],'773505685855836')); $bridge->tick();
    $ordered=$db->one('SELECT * FROM wa_sessions ORDER BY id DESC LIMIT 1');
    $db->run("UPDATE vicidial_live_chats SET status='LIVE',chat_creator='agent1' WHERE chat_id=?",[$ordered['chat_id']]);
    receive($webhook,envelope([message('ordered.2','Second buffered')],[],'773505685855836'));
    $db->run('UPDATE wa_inbox SET next_attempt=0'); $bridge->tick();
    $db->run('UPDATE wa_inbox SET next_attempt=0'); $bridge->tick();
    $texts=$db->all('SELECT message FROM vicidial_chat_log WHERE chat_id=? AND chat_level=0 ORDER BY message_row_id',[$ordered['chat_id']]);
    eq(array_column($texts,'message'),['First buffered','Second buffered'],'buffered message order');
    // Definite transient failure retries only when due; credential rotation is read live.
    $db->insert('vicidial_chat_log',['chat_id'=>$ordered['chat_id'],'message'=>'Retry reply','message_time'=>date('Y-m-d H:i:s'),'poster'=>'agent1','chat_member_name'=>'Agent','chat_level'=>0]);
    $sender->state='retry'; $before=count($sender->calls); $bridge->tick();
    $retry=$db->one('SELECT * FROM wa_outbox ORDER BY id DESC LIMIT 1');
    eq($retry['state'],'retry','definite transient failure');
    $bridge->tick(); eq(count($sender->calls),$before+1,'retry backoff');
    $sender->state='accepted'; $db->run('UPDATE wa_outbox SET next_attempt=0 WHERE id=?',[$retry['id']]); $bridge->tick();
    eq($db->one('SELECT state FROM wa_outbox WHERE id=?',[$retry['id']])['state'],'accepted','retry succeeds');
    // Failure after sent is meaningful; a later read still wins over older failures.
    receive($webhook,envelope([], [['id'=>'wamid.out.'.$retry['id'],'status'=>'sent','recipient_id'=>'919876543210','timestamp'=>(string)time()]],'773505685855836'));
    receive($webhook,envelope([], [['id'=>'wamid.out.'.$retry['id'],'status'=>'failed','errors'=>[['code'=>131026]],'recipient_id'=>'919876543210','timestamp'=>(string)time()]],'773505685855836'));
    $bridge->tick(); eq($db->one('SELECT state FROM wa_outbox WHERE id=?',[$retry['id']])['state'],'failed','failed delivery after sent');
    $failurePattern='%reply #'.$retry['id'].' could not be delivered%';
    eq((int)$db->one('SELECT COUNT(*) AS n FROM vicidial_chat_log WHERE chat_id=? AND chat_level=1 AND message LIKE ?',[$ordered['chat_id'],$failurePattern])['n'],1,'delivery webhook adds private failure notice');
    receive($webhook,envelope([], [['id'=>'wamid.out.'.$retry['id'],'status'=>'failed','errors'=>[['code'=>131026]],'recipient_id'=>'919876543210','timestamp'=>(string)(time()+1)]],'773505685855836'));
    $bridge->tick();
    eq((int)$db->one('SELECT COUNT(*) AS n FROM vicidial_chat_log WHERE chat_id=? AND message LIKE ?',[$ordered['chat_id'],$failurePattern])['n'],1,'repeated failure callback does not repeat notice');
    // Replay when the native row was reserved but not yet inserted.
    $reserved=(int)$db->one('SELECT MAX(message_row_id) AS n FROM vicidial_chat_log')['n']+10;
    $planned=['chat_id'=>$ordered['chat_id'],'message'=>'Reserved only','message_time'=>date('Y-m-d H:i:s'),'poster'=>$ordered['member'],'chat_member_name'=>'Name','chat_level'=>0];
    $db->insert('wa_vici_operations',['operation_key'=>'test:planned','target_table'=>'vicidial_chat_log','target_id'=>$reserved,'row_json'=>json_encode($planned),'state'=>'planned']);
    eq($db->nativeInsert('test:planned','vicidial_chat_log',[],'vicidial_chat_log_archive'),$reserved,'recover pre-insert crash');
    // Signed status-only callbacks must not create customer sessions.
    $sessionsBefore=(int)$db->one('SELECT COUNT(*) AS n FROM wa_sessions')['n'];
    receive($webhook,envelope([], [['id'=>'unrelated.template','status'=>'delivered','timestamp'=>(string)time()]])); $bridge->tick();
    eq((int)$db->one('SELECT COUNT(*) AS n FROM wa_sessions')['n'],$sessionsBefore,'template status does not create chat');
    // Disabled DID retains new incoming work but does not process it.
    $db->run("UPDATE vicidial_inbound_dids SET did_active='N' WHERE did_id=13");
    receive($webhook,envelope([message('disabled.1','On hold')],[],'773505685855836')); $bridge->tick();
    eq($db->one('SELECT state FROM wa_inbox ORDER BY id DESC LIMIT 1')['state'],'pending','disabled DID pauses inbox');
    $db->run("UPDATE vicidial_inbound_dids SET did_active='Y' WHERE did_id=13"); $bridge->tick();
    eq($db->one('SELECT state FROM wa_inbox ORDER BY id DESC LIMIT 1')['state'],'done','reenabling resumes inbox');
    // Callback retries do not extend the window; older messages use MAX(timestamp).
    $last=(int)$db->one('SELECT last_inbound FROM wa_sessions WHERE id=?',[$ordered['id']])['last_inbound'];
    receive($webhook,envelope([message('older.1','Delayed',-60)],[],'773505685855836')); $bridge->tick();
    eq((int)$db->one('SELECT last_inbound FROM wa_sessions WHERE id=?',[$ordered['id']])['last_inbound'],$last,'older message does not move expiry');
    // Long reply parts stay ordered and retain unicode; at most one part per session/tick.
    $db->insert('vicidial_chat_log',['chat_id'=>$ordered['chat_id'],'message'=>str_repeat('x',8001),'message_time'=>date('Y-m-d H:i:s'),'poster'=>'agent1','chat_member_name'=>'Agent','chat_level'=>0]);
    $before=count($sender->calls); $bridge->tick(); $bridge->tick(); $bridge->tick();
    eq(count($sender->calls),$before+3,'long reply parts delivered');
    eq(array_map(fn($c)=>strlen($c[2]),array_slice($sender->calls,-3)),[4000,4000,1],'reply split boundaries');
    // Reject duplicate route configuration rather than sending from an arbitrary DID.
    $db->run("UPDATE vicidial_inbound_dids SET custom_two='773505685855835' WHERE did_id=13");
    try { Preflight::check($db,$config); throw new LogicException('duplicate route accepted'); }
    catch (RuntimeException $e) { $count++; }
    $db->run("UPDATE vicidial_inbound_dids SET custom_two='773505685855836' WHERE did_id=13");
    // Database-wide worker exclusion, across independent connections.
    $secondLink=new mysqli('localhost',getenv('WA_TEST_USER') ?: 'root',getenv('WA_TEST_PASSWORD') ?: '',$name,0,$socket);
    eq((int)$db->one("SELECT GET_LOCK('wa_test_worker',0) AS n")['n'],1,'first worker lock');
    eq((int)$secondLink->query("SELECT GET_LOCK('wa_test_worker',0) AS n")->fetch_assoc()['n'],0,'second worker excluded');
    $db->run("SELECT RELEASE_LOCK('wa_test_worker')"); $secondLink->close();
    // Fail the second insert of a webhook batch: the first must survive and retry dedup.
    $firstKey=hash('sha256','773505685855835:message:partial.1');
    $secondKey=hash('sha256','773505685855835:message:partial.2');
    $db->run("CREATE TRIGGER wa_test_fail_batch BEFORE INSERT ON wa_inbox FOR EACH ROW BEGIN IF NEW.event_key='$secondKey' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected batch failure'; END IF; END");
    $batch=envelope([message('partial.1','Saved before failure'),message('partial.2','Saved on retry')]);
    try { receive($webhook,$batch); throw new RuntimeException('fault injection did not fail'); }
    catch (mysqli_sql_exception $e) { eq($e->getCode(),1644,'injected batch failure'); }
    eq((int)$db->one('SELECT COUNT(*) AS n FROM wa_inbox WHERE event_key=?',[$firstKey])['n'],1,'partial batch retains saved event');
    eq((int)$db->one('SELECT COUNT(*) AS n FROM wa_inbox WHERE event_key=?',[$secondKey])['n'],0,'failed event not acknowledged');
    $db->run('DROP TRIGGER wa_test_fail_batch'); receive($webhook,$batch); receive($webhook,$batch);
    eq((int)$db->one('SELECT COUNT(*) AS n FROM wa_inbox WHERE event_key IN (?,?)',[$firstKey,$secondKey])['n'],2,'partial batch retry saves each event once');

    // Simulate the session INSERT completing before its inbox mapping was interrupted.
    $origin=message('origin.recovery','Recover mapping'); $origin['from']='919812340000';
    receive($webhook,envelope([$origin]));
    $originEvent=$db->one('SELECT * FROM wa_inbox WHERE event_key=?',[hash('sha256','773505685855835:message:origin.recovery')]);
    $db->insert('wa_sessions',['did_id'=>12,'phone_number_id'=>'773505685855835','sender'=>$origin['from'],
        'group_id'=>'TSIM','member'=>'wa'.substr($originEvent['event_key'],0,18),'member_name'=>'Recovery',
        'last_inbound'=>time(),'created_at'=>time()]);
    $originSession=(int)$db->link->insert_id;
    $bridge->tick();
    eq((int)$db->one('SELECT session_id FROM wa_inbox WHERE id=?',[$originEvent['id']])['session_id'],$originSession,'interrupted mapping recovers original session');
    eq((int)$db->one('SELECT COUNT(*) AS n FROM wa_sessions WHERE sender=?',[$origin['from']])['n'],1,'no duplicate session after mapping crash');
    // Even if the session subsequently closed, replay must not create another chat.
    $saved=$db->one('SELECT * FROM wa_sessions WHERE id=?',[$originSession]);
    $bridge->close($saved,'window_expired');
    $db->run("UPDATE wa_inbox SET session_id=NULL,state='pending',next_attempt=0 WHERE id=?",[$originEvent['id']]);
    $bridge->tick();
    eq((int)$db->one('SELECT COUNT(*) AS n FROM wa_sessions WHERE sender=?',[$origin['from']])['n'],1,'closed origin session is not recreated');
    eq($db->one('SELECT state FROM wa_inbox WHERE id=?',[$originEvent['id']])['state'],'closed','closed origin event is terminal');
    // Incoming after 24h leaves an agent-owned chat intact and starts a fresh one.
    $late=message('late.live.1','Initial'); $late['from']='919812341111';
    receive($webhook,envelope([$late])); $bridge->tick();
    $old=$db->one('SELECT * FROM wa_sessions WHERE sender=? ORDER BY id DESC LIMIT 1',[$late['from']]);
    $db->run("UPDATE vicidial_live_chats SET status='LIVE',chat_creator='agent1' WHERE chat_id=?",[$old['chat_id']]);
    $db->run('UPDATE wa_inbox SET next_attempt=0'); $bridge->tick();
    $db->run('UPDATE wa_sessions SET last_inbound=? WHERE id=?',[time()-86400,$old['id']]);
    $late['id']='late.live.2'; receive($webhook,envelope([$late])); $bridge->tick();
    eq($db->one('SELECT state FROM wa_sessions WHERE id=?',[$old['id']])['state'],'closed','incoming after gap leaves old session');
    eq($db->one('SELECT status FROM vicidial_live_chats WHERE chat_id=?',[$old['chat_id']])['status'],'LIVE','incoming does not close agent chat');
    $renewed=$db->one('SELECT * FROM wa_sessions WHERE sender=? ORDER BY id DESC LIMIT 1',[$late['from']]);
    eq($renewed['chat_id']!==$old['chat_id'],true,'incoming starts distinct new chat after gap');
    // Interruption after recording a leave notice is safely finished on startup.
    $db->run("UPDATE vicidial_live_chats SET status='LIVE',chat_creator='agent1' WHERE chat_id=?",[$renewed['chat_id']]);
    $db->run('UPDATE wa_inbox SET next_attempt=0'); $bridge->tick();
    $db->run("CREATE TRIGGER wa_test_leave_failure BEFORE DELETE ON vicidial_chat_participants FOR EACH ROW BEGIN IF OLD.chat_id=".(int)$renewed['chat_id']." THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected leave failure'; END IF; END");
    try { $bridge->close($renewed,'window_expired'); throw new RuntimeException('leave failure not injected'); }
    catch (mysqli_sql_exception $e) { eq($e->getCode(),1644,'departure interrupted after notice'); }
    $db->run('DROP TRIGGER wa_test_leave_failure');
    $bridge=new Bridge($db,$sender,$config); $bridge->recover();
    eq($db->one('SELECT state FROM wa_sessions WHERE id=?',[$renewed['id']])['state'],'closed','unfinished departure recovered');
    eq((int)$db->one("SELECT COUNT(*) AS n FROM vicidial_chat_log WHERE chat_id=? AND message LIKE '%has left chat'",[$renewed['chat_id']])['n'],1,'recovered departure notice exactly once');
    eq($db->one('SELECT status FROM vicidial_live_chats WHERE chat_id=?',[$renewed['chat_id']])['status'],'LIVE','recovery retains agent chat');

    // Cursor advancement waits for every outbox part, even on MyISAM failures.
    $db->insert('vicidial_chat_log',['chat_id'=>$ordered['chat_id'],'message'=>str_repeat('z',4001),'message_time'=>date('Y-m-d H:i:s'),'poster'=>'agent1','chat_member_name'=>'Agent','chat_level'=>0]);
    $source=(int)$db->link->insert_id;
    $db->run("CREATE TRIGGER wa_test_outbox_failure BEFORE INSERT ON wa_outbox FOR EACH ROW BEGIN IF NEW.source_id=$source AND NEW.part=1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected queue failure'; END IF; END");
    $cursorBefore=$db->one("SELECT last_id FROM wa_worker_state WHERE name='chat_log'")['last_id'];
    try { $bridge->tick(); throw new RuntimeException('queue failure not injected'); }
    catch (mysqli_sql_exception $e) { eq($e->getCode(),1644,'outbox part failure injected'); }
    eq($db->one("SELECT last_id FROM wa_worker_state WHERE name='chat_log'")['last_id'],$cursorBefore,'cursor not advanced after partial queue write');
    $db->run('DROP TRIGGER wa_test_outbox_failure');
    $bridge=new Bridge($db,$sender,$config); $bridge->recover(); $bridge->tick(); $bridge->tick();
    eq((int)$db->one('SELECT COUNT(*) AS n FROM wa_outbox WHERE source_id=?',[$source])['n'],2,'restart completes parts without duplicates');
    // Do not advance past replies while a new native chat's mapping is incomplete.
    $db->insert('wa_sessions',['did_id'=>12,'phone_number_id'=>'773505685855835','sender'=>'incomplete','group_id'=>'TSIM',
        'member'=>'incomplete','member_name'=>'Incomplete','created_at'=>time(),'last_inbound'=>time()]);
    $db->insert('vicidial_chat_log',['chat_id'=>$ordered['chat_id'],'message'=>'After provisioning','message_time'=>date('Y-m-d H:i:s'),'poster'=>'agent1','chat_member_name'=>'Agent','chat_level'=>0]);
    $cursorBefore=$db->one("SELECT last_id FROM wa_worker_state WHERE name='chat_log'")['last_id'];
    $bridge->tick();
    eq($db->one("SELECT last_id FROM wa_worker_state WHERE name='chat_log'")['last_id'],$cursorBefore,'unfinished chat mapping holds cursor');
    $db->run("DELETE FROM wa_sessions WHERE member='incomplete'"); $bridge->tick();
    eq(end($sender->calls)[2],'After provisioning','cursor resumes after provisioning recovery');
    // A later archive move below the cursor must not resend a processed reply.
    $before=count($sender->calls);
    $db->run('INSERT INTO vicidial_chat_log_archive SELECT * FROM vicidial_chat_log WHERE message_row_id=?',[$source]);
    $db->run('DELETE FROM vicidial_chat_log WHERE message_row_id=?',[$source]); $bridge->tick();
    eq(count($sender->calls),$before,'late archival does not resend old reply');

    // A small cursor batch still picks up a reply archived between worker ticks.
    $configSmall=$config; $configSmall['reply_batch_size']=1;
    $poller=new Bridge($db,$sender,$configSmall);
    $before=count($sender->calls);
    for ($n=0;$n<3;$n++) {
        $db->insert('vicidial_chat_log',['chat_id'=>$ordered['chat_id'],'message'=>'Page '.$n,'message_time'=>date('Y-m-d H:i:s'),'poster'=>'agent1','chat_member_name'=>'Agent','chat_level'=>0]);
        if ($n===0) {
            $id=(int)$db->link->insert_id;
            $db->run('INSERT INTO vicidial_chat_log_archive SELECT * FROM vicidial_chat_log WHERE message_row_id=?',[$id]);
            $db->run('DELETE FROM vicidial_chat_log WHERE message_row_id=?',[$id]);
        }
    }
    $poller->tick(); $poller->tick(); $poller->tick();
    eq(array_column(array_slice($sender->calls,$before),2),['Page 0','Page 1','Page 2'],'shared cursor orders live and archived batches');

    // A pending recent customer message defers expiry of an actual agent reply.
    $db->run('UPDATE wa_sessions SET last_inbound=? WHERE id=?',[time()-86400,$ordered['id']]);
    $deferred=message('deferred.new','Still here',-20);
    receive($webhook,envelope([$deferred],[],'773505685855836'));
    $db->run('UPDATE wa_inbox SET next_attempt=? WHERE event_key=?',[time()+60,hash('sha256','773505685855836:message:deferred.new')]);
    $db->insert('vicidial_chat_log',['chat_id'=>$ordered['chat_id'],'message'=>'After backlog','message_time'=>date('Y-m-d H:i:s'),'poster'=>'agent1','chat_member_name'=>'Agent','chat_level'=>0]);
    $before=count($sender->calls); heartbeatAt($bridge,0); $bridge->tick();
    eq($db->one('SELECT state FROM wa_sessions WHERE id=?',[$ordered['id']])['state'],'active','pending inbound avoids false outbound expiry');
    eq((int)$db->one("SELECT ping_date>=DATE_SUB(NOW(),INTERVAL 2 SECOND) AS fresh FROM vicidial_chat_participants WHERE chat_id=? AND vd_agent='N'",[$ordered['chat_id']])['fresh'],1,'queued customer remains present');
    $bridge->close($ordered,'window_expired',true);
    eq($db->one('SELECT state FROM wa_sessions WHERE id=?',[$ordered['id']])['state'],'active','locked expiry recheck protects newly queued message');
    eq(count($sender->calls),$before,'outbound waits for pending inbound');
    $db->run('UPDATE wa_inbox SET next_attempt=0'); $bridge->tick();
    eq(count($sender->calls),$before+1,'send resumes after recent customer event');

    // With no message work, query count is independent of the number of sessions.
    $bridge->tick();
    heartbeatAt($bridge,time());
    $before=(int)$db->one("SHOW SESSION STATUS LIKE 'Com_select'")['Value']; $bridge->tick();
    $quietQueries=(int)$db->one("SHOW SESSION STATUS LIKE 'Com_select'")['Value']-$before;
    for ($n=0;$n<200;$n++) {
        $db->insert('wa_sessions',['did_id'=>12,'phone_number_id'=>'773505685855835','sender'=>'quiet'.$n,'group_id'=>'TSIM',
            'member'=>'quiet'.$n,'member_name'=>'Quiet','state'=>'active','created_at'=>time()-90000,'last_inbound'=>time()]);
    }
    heartbeatAt($bridge,time());
    $before=(int)$db->one("SHOW SESSION STATUS LIKE 'Com_select'")['Value']; $bridge->tick();
    eq((int)$db->one("SHOW SESSION STATUS LIKE 'Com_select'")['Value']-$before,$quietQueries,'idle SELECT count independent of 200 more sessions');
    eq((int)$db->one("SELECT COUNT(*) AS n FROM wa_sessions WHERE member LIKE 'quiet%' AND state='active'")['n'],200,'unexpired sessions retained');
    heartbeatAt($bridge,0);
    $before=(int)$db->one("SHOW SESSION STATUS LIKE 'Com_select'")['Value']; $bridge->tick();
    $maintenanceQueries=(int)$db->one("SHOW SESSION STATUS LIKE 'Com_select'")['Value']-$before;
    eq($maintenanceQueries,$quietQueries+1,'maintenance adds only one SELECT with no expired sessions');
    $db->run("DELETE FROM wa_sessions WHERE member LIKE 'quiet%'");

    // Persistent customer insertion failures terminate after eight attempts and
    // stop blocking subsequent messages. Retries are due work, not every tick.
    $broken=message('processing.fail','Customer text that must stay private');
    receive($webhook,envelope([$broken],[],'773505685855836'));
    $brokenEvent=$db->one('SELECT * FROM wa_inbox ORDER BY id DESC LIMIT 1');
    $db->run("CREATE TRIGGER wa_test_inbound_failure BEFORE INSERT ON vicidial_chat_log FOR EACH ROW BEGIN IF NEW.chat_id=".(int)$ordered['chat_id']." AND NEW.poster='".$ordered['member']."' AND NEW.chat_level=0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected customer insert failure'; END IF; END");
    for ($attempt=1;$attempt<=8;$attempt++) {
        $db->run('UPDATE wa_inbox SET next_attempt=0 WHERE id=?',[$brokenEvent['id']]);
        $bridge->tick();
        $result=$db->one('SELECT * FROM wa_inbox WHERE id=?',[$brokenEvent['id']]);
        eq((int)$result['attempts'],$attempt,'persistent error attempt '.$attempt);
        eq($result['state'],$attempt===8?'failed':'retry','bounded processing attempt '.$attempt);
        if ($attempt===1) {
            eq((int)$result['next_attempt']>=time()+29,true,'inbound retry waits thirty seconds');
            $bridge->tick();
            eq((int)$db->one('SELECT attempts FROM wa_inbox WHERE id=?',[$brokenEvent['id']])['attempts'],1,'not retried before due');
            receive($webhook,envelope([message('processing.following','Following customer text')],[],'773505685855836'));
        }
    }
    $db->run('DROP TRIGGER wa_test_inbound_failure');
    $db->run('UPDATE wa_inbox SET next_attempt=0'); $bridge->tick();
    eq($db->one('SELECT state FROM wa_inbox WHERE event_key=?',[hash('sha256','773505685855836:message:processing.following')])['state'],'done','later customer message resumes after permanent failure');
    $bridge->tick();
    eq((int)$db->one('SELECT attempts FROM wa_inbox WHERE id=?',[$brokenEvent['id']])['attempts'],8,'failed customer message is no longer retried');
    receive($webhook,envelope([message('processing.invalid','Invalid stored JSON')],[],'773505685855836'));
    $invalidId=(int)$db->link->insert_id;
    $db->run("UPDATE wa_inbox SET payload='{' WHERE id=?",[$invalidId]); $bridge->tick();
    eq($db->one('SELECT state FROM wa_inbox WHERE id=?',[$invalidId])['state'],'failed','invalid stored payload fails immediately');
    eq($db->one('SELECT error_code FROM wa_inbox WHERE id=?',[$invalidId])['error_code'],'invalid_payload','invalid payload is classified');

    // A failed last provisioning attempt cannot hold the global reply cursor.
    $partial=message('processing.provision','Cannot finish provisioning'); $partial['from']='919812347777';
    receive($webhook,envelope([$partial]));
    $partialId=(int)$db->link->insert_id;
    $db->run('UPDATE wa_inbox SET attempts=7 WHERE id=?',[$partialId]);
    $db->run("CREATE TRIGGER wa_test_provision_failure BEFORE INSERT ON vicidial_chat_log FOR EACH ROW BEGIN IF NEW.message LIKE 'WhatsApp %Receiving number%' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected provisioning failure'; END IF; END");
    $bridge->tick(); $db->run('DROP TRIGGER wa_test_provision_failure');
    eq($db->one('SELECT state FROM wa_inbox WHERE id=?',[$partialId])['state'],'failed','last provisioning attempt terminates');
    eq($db->one('SELECT state FROM wa_sessions WHERE sender=?',[$partial['from']])['state'],'closed','abandoned provisioning releases reply cursor');

    // Permanent sends create a private note without echoing it to WhatsApp.
    $db->insert('vicidial_chat_log',['chat_id'=>$ordered['chat_id'],'message'=>'Rejected reply','message_time'=>date('Y-m-d H:i:s'),'poster'=>'agent1','chat_member_name'=>'Agent','chat_level'=>0]);
    $sender->state='failed'; $bridge->tick();
    $failedOut=$db->one('SELECT * FROM wa_outbox ORDER BY id DESC LIMIT 1');
    eq($failedOut['state'],'failed','permanent outbound rejection');
    $pattern='%reply #'.$failedOut['id'].' could not be delivered%';
    eq((int)$db->one('SELECT COUNT(*) AS n FROM vicidial_chat_log WHERE chat_id=? AND chat_level=1 AND message LIKE ?',[$ordered['chat_id'],$pattern])['n'],1,'permanent rejection visible privately');
    $before=count($sender->calls); $bridge->tick();
    eq(count($sender->calls),$before,'private failure note never sent to customer');

    // Exhausted outbound retries also notify once.
    $db->insert('vicidial_chat_log',['chat_id'=>$ordered['chat_id'],'message'=>'Exhausted retry','message_time'=>date('Y-m-d H:i:s'),'poster'=>'agent1','chat_member_name'=>'Agent','chat_level'=>0]);
    $sender->state='retry'; $bridge->tick();
    $exhausted=$db->one('SELECT * FROM wa_outbox ORDER BY id DESC LIMIT 1');
    $db->run('UPDATE wa_outbox SET attempts=7,next_attempt=0 WHERE id=?',[$exhausted['id']]); $bridge->tick();
    eq($db->one('SELECT state FROM wa_outbox WHERE id=?',[$exhausted['id']])['state'],'failed','outbound retries terminate');
    eq((int)$db->one('SELECT COUNT(*) AS n FROM vicidial_chat_log WHERE chat_id=? AND chat_level=1 AND message LIKE ?',[$ordered['chat_id'],'%reply #'.$exhausted['id'].' could not be delivered%'])['n'],1,'retry exhaustion notice');

    // Crash after notice insertion but before its durable flag is cleared.
    $db->insert('vicidial_chat_log',['chat_id'=>$ordered['chat_id'],'message'=>'Uncertain during restart','message_time'=>date('Y-m-d H:i:s'),'poster'=>'agent1','chat_member_name'=>'Agent','chat_level'=>0]);
    $db->run("CREATE TRIGGER wa_test_notice_failure BEFORE UPDATE ON wa_outbox FOR EACH ROW BEGIN IF OLD.notice_pending IS NOT NULL AND NEW.notice_pending IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected notice completion failure'; END IF; END");
    $sender->state='uncertain';
    try { $bridge->tick(); throw new RuntimeException('notice interruption not injected'); }
    catch (mysqli_sql_exception $e) { eq($e->getCode(),1644,'notice completion interrupted'); }
    $uncertain=$db->one('SELECT * FROM wa_outbox ORDER BY id DESC LIMIT 1');
    eq($uncertain['notice_pending'],'uncertain','notice recovery flag survives interruption');
    $db->run('DROP TRIGGER wa_test_notice_failure');
    $bridge=new Bridge($db,$sender,$config); $bridge->recover(); $bridge->recover();
    eq($db->one('SELECT notice_pending FROM wa_outbox WHERE id=?',[$uncertain['id']])['notice_pending'],null,'startup completes pending notice');
    eq((int)$db->one('SELECT COUNT(*) AS n FROM vicidial_chat_log WHERE chat_id=? AND chat_level=1 AND message LIKE ?',[$ordered['chat_id'],'%reply #'.$uncertain['id'].' is uncertain%'])['n'],1,'notice remains single after restart');
    $db->insert('vicidial_chat_log',['chat_id'=>$ordered['chat_id'],'message'=>'After uncertain reply','message_time'=>date('Y-m-d H:i:s'),'poster'=>'agent1','chat_member_name'=>'Agent','chat_level'=>0]);
    $sender->state='accepted'; $before=count($sender->calls); $bridge->tick();
    eq(count($sender->calls),$before,'uncertain send still holds following replies');
    receive($webhook,envelope([], [['id'=>'wamid.recovered','status'=>'delivered','recipient_id'=>'919876543210','biz_opaque_callback_data'=>'wa:'.$uncertain['id'],'timestamp'=>(string)time()]],'773505685855836'));
    $bridge->tick();
    eq(count($sender->calls),$before+1,'delivery callback releases following reply');

    // Status is a retained-event report, with customer and notification totals
    // separate and no customer text, phone numbers or credentials printed.
    $report=\WaChat\Status::report($db);
    $messages=(int)$db->one("SELECT COUNT(*) AS n FROM wa_inbox WHERE kind='message'")['n'];
    $statuses=(int)$db->one("SELECT COUNT(*) AS n FROM wa_inbox WHERE kind='status'")['n'];
    eq(strpos($report,'Customer messages: '.$messages)!==false,true,'status separates customer message total');
    eq(strpos($report,'Delivery notifications: '.$statuses)!==false,true,'status separates delivery notification total');
    eq(strpos($report,'includes processed history')!==false,true,'status explains retained history');
    eq(strpos($report,'accepted / delivered:')!==false,true,'status distinguishes acceptance from delivery');
    foreach (['Customer text that must stay private','919876543210',str_repeat('x',202),str_repeat('a',32)] as $secret) {
        eq(strpos($report,$secret),false,'status excludes sensitive content');
    }

    // Bounded maintenance leaves excess departures for the next pass, but
    // never pings an expired customer just because that pass is full.
    $aged=[];
    for ($n=0;$n<2;$n++) {
        $msg=message('maintenance.batch.'.$n,'Unanswered'); $msg['from']='91981234888'.$n;
        receive($webhook,envelope([$msg])); $bridge->tick();
        $s=$db->one('SELECT * FROM wa_sessions WHERE sender=?',[$msg['from']]);
        $aged[]=$s;
    }
    foreach ($aged as $n=>$s) {
        $timestamp=time()-90000+$n;
        $db->run('UPDATE wa_sessions SET last_inbound=? WHERE id=?',[$timestamp,$s['id']]);
        $db->run("UPDATE wa_inbox SET message_timestamp=?,payload=JSON_SET(payload,'$.timestamp',?) WHERE session_id=?",[$timestamp,(string)$timestamp,$s['id']]);
        $db->run("UPDATE vicidial_chat_participants SET ping_date='2000-01-01 00:00:00' WHERE chat_id=?",[$s['chat_id']]);
    }
    $smallMaintenance=$config; $smallMaintenance['expiry_batch_size']=1;
    $maintenance=new Bridge($db,$sender,$smallMaintenance); $maintenance->tick();
    eq($db->one('SELECT state FROM wa_sessions WHERE id=?',[$aged[0]['id']])['state'],'closed','maintenance closes oldest within batch');
    eq($db->one('SELECT state FROM wa_sessions WHERE id=?',[$aged[1]['id']])['state'],'active','next departure deferred by batch limit');
    eq($db->one('SELECT ping_date FROM vicidial_chat_participants WHERE chat_id=?',[$aged[1]['chat_id']])['ping_date'],'2000-01-01 00:00:00','expired backlog never kept alive');
    $maintenance->tick();
    eq($db->one('SELECT state FROM wa_sessions WHERE id=?',[$aged[1]['id']])['state'],'active','maintenance is not run every second');
    $future=message('maintenance.future','Invalid future message',3600); $future['from']=$aged[1]['sender'];
    receive($webhook,envelope([$future]));
    $futureId=(int)$db->link->insert_id;
    $db->run('UPDATE wa_inbox SET next_attempt=? WHERE id=?',[time()+60,$futureId]);
    heartbeatAt($maintenance,0); $maintenance->tick();
    eq($db->one('SELECT state FROM wa_sessions WHERE id=?',[$aged[1]['id']])['state'],'closed','future timestamp cannot prevent expiry');
    $db->run('UPDATE wa_inbox SET next_attempt=0 WHERE id=?',[$futureId]); $bridge->tick();

    // Keep the final outbound guard between maintenance passes.
    $db->run('UPDATE wa_sessions SET last_inbound=? WHERE id=?',[time()-86400,$ordered['id']]);
    $db->insert('vicidial_chat_log',['chat_id'=>$ordered['chat_id'],'message'=>'Expired between maintenance passes','message_time'=>date('Y-m-d H:i:s'),'poster'=>'agent1','chat_member_name'=>'Agent','chat_level'=>0]);
    heartbeatAt($bridge,time()); $before=count($sender->calls); $bridge->tick();
    eq(count($sender->calls),$before,'final expiry guard prevents send between maintenance passes');
    eq($db->one('SELECT state FROM wa_outbox ORDER BY id DESC LIMIT 1')['state'],'expired','outbound expiry is recorded');

    // Media uses the existing attachment directory and native chat log, with no schema migration.
    $mediaRoot=sys_get_temp_dir().'/wa-media-test-'.bin2hex(random_bytes(6));
    mkdir($mediaRoot.'/attachments',0755,true); mkdir($mediaRoot.'/checkout/chat_customer',0755,true);
    $db->run("ALTER TABLE system_settings ADD sounds_web_directory VARCHAR(255) DEFAULT 'attachments'");
    $mediaConfig=$config; $mediaConfig['chat_directory']=$mediaRoot.'/checkout/chat_customer';
    $download=new class implements \WaChat\MediaDownloader {
        public int $calls=0;
        public bool $fail=false;
        public bool $corrupt=false;
        public function download(array $account,string $id,string $path,int $limit): array {
            $this->calls++;
            if ($this->fail) { throw new \WaChat\MediaFailure('media_transport',true); }
            file_put_contents($path,$this->corrupt?'corrupt':'test image bytes');
            return ['mime_type'=>'image/png'];
        }
    };
    $media=new \WaChat\Media($db,$mediaConfig,$download);
    eq($media->location(),[realpath($mediaRoot).'/attachments/wa_media','/attachments/wa_media'],'discover web root above nested checkout');
    $mediaBridge=new Bridge($db,$sender,$mediaConfig,$media);
    $image=message('media.1',''); $image['from']='919812349999'; $image['type']='image'; unset($image['text']);
    $image['image']=['id'=>'123456','mime_type'=>'image/png','sha256'=>base64_encode(hash('sha256','test image bytes',true)),
        'caption'=>'Receipt <script>alert(1)</script> 😀','filename'=>'../../evil.php'];
    receive($webhook,envelope([$image])); $mediaBridge->tick();
    $mediaSession=$db->one('SELECT * FROM wa_sessions WHERE sender=?',[$image['from']]);
    eq($download->calls,0,'do not download before agent accepts');
    $db->run("UPDATE vicidial_live_chats SET status='LIVE',chat_creator='agent1' WHERE chat_id=?",[$mediaSession['chat_id']]);
    $db->run('UPDATE wa_inbox SET next_attempt=0'); $mediaBridge->tick();
    $mediaLog=$db->one('SELECT message FROM vicidial_chat_log WHERE chat_id=? AND chat_level=0',[$mediaSession['chat_id']])['message'];
    eq(strpos($mediaLog,'<a href="/attachments/wa_media/')===0,true,'media link written to native chat');
    eq(strpos($mediaLog,'<script>'),false,'caption HTML escaped');
    eq(count(glob($mediaRoot.'/attachments/wa_media/*.png')),1,'safe filename and extension');
    eq($download->calls,1,'one download');
    receive($webhook,envelope([$image])); $mediaBridge->tick();
    eq((int)$db->one('SELECT COUNT(*) AS n FROM vicidial_chat_log WHERE chat_id=? AND chat_level=0',[$mediaSession['chat_id']])['n'],1,'duplicate callback does not duplicate media log');
    $account=(new \WaChat\Accounts($db))->all()['773505685855835'];
    $media->html($image,$account); eq($download->calls,1,'cached file reused after interrupted insertion');
    $image['id']='media.retry'; $image['image']['id']='123457'; $download->fail=true;
    receive($webhook,envelope([$image])); $mediaBridge->tick();
    $event=$db->one("SELECT * FROM wa_inbox ORDER BY id DESC LIMIT 1");
    eq($event['state'],'retry','transient download failure retained');
    eq($event['error_code'],'media_transport','useful media error without secrets');
    $download->fail=false; $db->run('UPDATE wa_inbox SET next_attempt=0'); $mediaBridge->tick();
    eq($db->one('SELECT state FROM wa_inbox WHERE id=?',[$event['id']])['state'],'done','media retry delivered');
    $image['id']='media.hash'; $image['image']['id']='123458'; $download->corrupt=true;
    receive($webhook,envelope([$image])); $mediaBridge->tick();
    $event=$db->one("SELECT * FROM wa_inbox ORDER BY id DESC LIMIT 1");
    eq($event['error_code'],'media_hash_mismatch','corrupt download rejected');
    eq(count(glob($mediaRoot.'/attachments/wa_media/.download-*')),0,'partial files cleaned');
    $db->run('UPDATE wa_inbox SET attempts=7,next_attempt=0 WHERE id=?',[$event['id']]); $mediaBridge->tick();
    eq($db->one('SELECT state FROM wa_inbox WHERE id=?',[$event['id']])['state'],'failed','download retries bounded');
    $last=$db->one('SELECT message FROM vicidial_chat_log WHERE chat_id=? AND chat_level=0 ORDER BY message_row_id DESC LIMIT 1',[$mediaSession['chat_id']]);
    eq(strpos($last['message'],'media_hash_mismatch')!==false,true,'agent sees failed attachment');
    $download->corrupt=false;
    $image['image']['mime_type']='text/html';
    try { $media->html($image,$account); throw new RuntimeException('unsafe MIME accepted'); }
    catch (\WaChat\MediaFailure $e) { eq($e->reason,'media_unsupported_type','reject active media'); }
    foreach (glob($mediaRoot.'/attachments/wa_media/*') as $file) { unlink($file); }
    unlink($mediaRoot.'/attachments/wa_media/.htaccess'); rmdir($mediaRoot.'/attachments/wa_media');
    rmdir($mediaRoot.'/attachments'); rmdir($mediaRoot.'/checkout/chat_customer'); rmdir($mediaRoot.'/checkout'); rmdir($mediaRoot);
    echo "Idle SELECTs per cycle: $quietQueries (unchanged with 200 additional sessions)\n";
    $engine=in_array('--innodb',$argv,true)?'InnoDB':'MyISAM';
    echo "PASS: $count MariaDB integration checks ($engine native and bridge tables)\n";
} finally {
    $link->query('UNLOCK TABLES');
    $link->query("DROP DATABASE `$name`");
}
