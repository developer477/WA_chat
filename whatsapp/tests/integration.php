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
try {
    loadSql($link,__DIR__.'/schema.sql'); loadSql($link,dirname(__DIR__).'/sql/001_bridge.sql');
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
    eq((int)$db->one('SELECT COUNT(*) AS n FROM vicidial_chat_log')['n'],0,'no rejected/lost waiting message');
    $chat=$session['chat_id'];
    $db->run("UPDATE vicidial_live_chats SET status='LIVE',chat_creator='agent1' WHERE chat_id=?",[$chat]);
    $db->run('UPDATE wa_inbox SET next_attempt=0'); $bridge->tick();
    eq($db->one('SELECT state FROM wa_inbox')['state'],'done','waiting message delivered');
    eq((int)$db->one('SELECT COUNT(*) AS n FROM vicidial_chat_log')['n'],1,'one customer message');
    $stored=$db->one('SELECT message FROM vicidial_chat_log')['message'];
    eq(strpos($stored,'<script>'),false,'customer HTML not executable');
    eq(strpos($stored,'&#128512;')!==false,true,'emoji retained in legacy utf8');
    receive($webhook,$body); $bridge->tick();
    eq((int)$db->one('SELECT COUNT(*) AS n FROM vicidial_chat_log')['n'],1,'no duplicate native message');
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
    // Existing remaining live session reaches exact 24-hour expiry.
    $db->run('UPDATE wa_sessions SET last_inbound=? WHERE id=2',[time()-86400]);
    $bridge->tick();
    eq($db->one('SELECT state FROM wa_sessions WHERE id=2')['state'],'closed','expiry complete');
    eq($db->one('SELECT chat_id FROM vicidial_live_chats WHERE chat_id=?',[$second['chat_id']]),null,'expired live row removed');
    eq($db->one('SELECT status FROM vicidial_chat_archive WHERE chat_id=?',[$second['chat_id']])['status'],'DEAD','expiry archived');
    eq((int)$db->one('SELECT COUNT(*) AS n FROM vicidial_chat_participants WHERE chat_id=?',[$second['chat_id']])['n'],0,'expired participants removed');
    receive($webhook,envelope([message('after.close','New conversation')],[],'773505685855836')); $bridge->tick();
    eq((int)$db->one('SELECT COUNT(*) AS n FROM wa_sessions')['n'],3,'new chat after expiry');
    $third=$db->one('SELECT * FROM wa_sessions WHERE id=3');
    $db->run('UPDATE wa_sessions SET last_inbound=? WHERE id=3',[time()-86400]);
    $bridge->tick();
    eq($db->one('SELECT status FROM vicidial_chat_archive WHERE chat_id=?',[$third['chat_id']])['status'],'DROP','waiting expiry drop');
    eq($db->one('SELECT state FROM wa_inbox ORDER BY id DESC LIMIT 1')['state'],'expired','waiting message retained as expired');
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
    $texts=$db->all('SELECT message FROM vicidial_chat_log WHERE chat_id=? ORDER BY message_row_id',[$ordered['chat_id']]);
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
    $mediaLog=$db->one('SELECT message FROM vicidial_chat_log WHERE chat_id=?',[$mediaSession['chat_id']])['message'];
    eq(strpos($mediaLog,'<a href="/attachments/wa_media/')===0,true,'media link written to native chat');
    eq(strpos($mediaLog,'<script>'),false,'caption HTML escaped');
    eq(count(glob($mediaRoot.'/attachments/wa_media/*.png')),1,'safe filename and extension');
    eq($download->calls,1,'one download');
    receive($webhook,envelope([$image])); $mediaBridge->tick();
    eq((int)$db->one('SELECT COUNT(*) AS n FROM vicidial_chat_log WHERE chat_id=?',[$mediaSession['chat_id']])['n'],1,'duplicate callback does not duplicate media log');
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
    $last=$db->one('SELECT message FROM vicidial_chat_log WHERE chat_id=? ORDER BY message_row_id DESC LIMIT 1',[$mediaSession['chat_id']]);
    eq(strpos($last['message'],'media_hash_mismatch')!==false,true,'agent sees failed attachment');
    $download->corrupt=false;
    $image['image']['mime_type']='text/html';
    try { $media->html($image,$account); throw new RuntimeException('unsafe MIME accepted'); }
    catch (\WaChat\MediaFailure $e) { eq($e->reason,'media_unsupported_type','reject active media'); }
    foreach (glob($mediaRoot.'/attachments/wa_media/*') as $file) { unlink($file); }
    unlink($mediaRoot.'/attachments/wa_media/.htaccess'); rmdir($mediaRoot.'/attachments/wa_media');
    rmdir($mediaRoot.'/attachments'); rmdir($mediaRoot.'/checkout/chat_customer'); rmdir($mediaRoot.'/checkout'); rmdir($mediaRoot);
    $engine=in_array('--innodb',$argv,true)?'InnoDB':'MyISAM';
    echo "PASS: $count MariaDB integration checks ($engine native and bridge tables)\n";
} finally {
    $link->query('UNLOCK TABLES');
    $link->query("DROP DATABASE `$name`");
}
