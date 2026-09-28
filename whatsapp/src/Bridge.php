<?php
namespace WaChat;

final class Bridge
{
    private Database $db;
    private Accounts $accounts;
    private Sender $sender;
    private array $config;
    private string $logKey;
    private int $lastHeartbeat = 0;
    private ?Media $media;
    private ReplyPoller $replies;

    public function __construct(Database $db, Sender $sender, array $config, ?Media $media = null)
    {
        $this->db=$db; $this->accounts=new Accounts($db); $this->sender=$sender; $this->config=$config;
        $this->logKey=$db->primary('vicidial_chat_log');
        $this->replies=new ReplyPoller($db);
        $this->media=$media ?? ($sender instanceof MediaDownloader ? new Media($db,$config,$sender) : null);
    }

    public function recover(): void
    {
        // A terminated process may have sent the HTTP request. Never resend it blindly.
        $this->db->run("UPDATE wa_outbox SET state='uncertain',error_code='worker_interrupted',notice_pending='uncertain' WHERE state='sending'");
        foreach ($this->db->all("SELECT * FROM wa_sessions WHERE state='closing'") as $session) {
            $this->close($session,$session['close_reason'] ?: 'window_expired');
        }
        // Event-driven notices need only this startup recovery, never an idle poll.
        while ($rows=$this->db->all('SELECT id FROM wa_outbox WHERE notice_pending IS NOT NULL ORDER BY id LIMIT 100')) {
            foreach ($rows as $row) { $this->outboundNotice((int)$row['id']); }
        }
    }

    public function tick(): void
    {
        $accounts = $this->accounts->all();
        if ((int)($this->db->one('SELECT allow_chats FROM system_settings')['allow_chats'] ?? 0) < 1) {
            throw new \RuntimeException('VICIdial chats are disabled');
        }
        $now = time();
        $limit = max(1, min(1000, (int)$this->config['batch_size']));
        // Only revisit buffered messages when the customer can actually deliver
        // them (agent accepted), or the native chat has disappeared.
        $events = $this->db->all("SELECT i.* FROM wa_inbox i
            LEFT JOIN wa_sessions s ON s.id=i.session_id
            LEFT JOIN vicidial_live_chats c ON c.chat_id=s.chat_id
            WHERE i.next_attempt<=? AND (i.state IN ('pending','retry') OR
                (i.state='waiting' AND (c.status='LIVE' OR c.chat_id IS NULL)))
            ORDER BY (i.state='waiting'),i.id LIMIT $limit", [$now]);
        foreach ($events as $event) {
            $noticeId=null;
            try {
                if ($event['kind'] === 'status') { $noticeId=$this->status($event); }
                elseif (isset($accounts[$event['phone_number_id']])) { $this->incoming($event, $accounts[$event['phone_number_id']]); }
            } catch (\Throwable $e) {
                $this->inboundFailure($event,$e);
            }
            // A notice write failure must restart the worker and recover the
            // durable flag, rather than consume retries of an unrelated event.
            if ($noticeId!==null) { $this->outboundNotice($noticeId); }
            $this->heartbeat();
        }
        $this->replies->collect(max(1,min(10000,(int)($this->config['reply_batch_size'] ?? 1000))));
        $this->deliver($accounts, $limit);
        $this->heartbeat();
    }

    private function heartbeat(): void
    {
        $now=time();
        if ($now - $this->lastHeartbeat < 5) { return; }
        $this->lastHeartbeat = $now;
        $cutoff=$now-86400;
        $limit=max(1,min(1000,(int)($this->config['expiry_batch_size'] ?? 100)));
        // Both lookups are indexed: only expired active sessions and their
        // unprocessed customer events, never all retained webhook JSON.
        $queued="EXISTS (SELECT 1 FROM wa_inbox i WHERE i.phone_number_id=s.phone_number_id AND i.sender=s.sender
            AND i.state IN ('pending','retry','waiting') AND i.message_timestamp>s.last_inbound
            AND i.message_timestamp>? AND i.message_timestamp<=?)";
        foreach ($this->db->all("SELECT s.* FROM wa_sessions s WHERE s.state='active' AND s.last_inbound<=?
            AND NOT $queued ORDER BY s.last_inbound,s.id LIMIT $limit",[$cutoff,$cutoff,$now+300]) as $session) {
            $this->close($session,'window_expired',true);
        }
        // Even if departures need several batches, stale customers are not
        // kept alive. A genuine fresh queued message protects its participant.
        $this->db->run("UPDATE vicidial_chat_participants p JOIN wa_sessions s ON s.chat_id=p.chat_id AND s.member=p.chat_member
            JOIN vicidial_live_chats c ON c.chat_id=s.chat_id SET p.ping_date=NOW()
            WHERE s.state='active' AND p.vd_agent='N' AND (s.last_inbound>? OR $queued)",[$cutoff,$cutoff,$now+300]);
    }

    private function inboundFailure(array $event, \Throwable $error): void
    {
        $attempts=(int)$event['attempts']+1;
        $invalid=$error instanceof \JsonException || $error instanceof \InvalidArgumentException;
        $state=$invalid || $attempts>=8 ? 'failed' : 'retry';
        $code=$invalid?'invalid_payload':'processing_error';
        if ($state==='failed') {
            // Do not leave an abandoned partial chat blocking the shared reply
            // cursor. Departure is journaled before making the event terminal.
            $session=$this->db->one("SELECT s.* FROM wa_sessions s JOIN wa_inbox i ON i.id=?
                WHERE s.state='provisioning' AND (s.id=i.session_id OR s.member=?)",
                [$event['id'],'wa'.substr($event['event_key'],0,18)]);
            if ($session) { $this->close($session,'inbound_failed'); }
        }
        $delay=min(300,30*(2 ** min(4,$attempts-1)));
        $this->db->run('UPDATE wa_inbox SET state=?,attempts=?,next_attempt=?,error_code=? WHERE id=?',
            [$state,$attempts,$state==='retry'?time()+$delay:0,$code,$event['id']]);
        // No SQL, message bodies or credentials in logs/error fields.
        error_log('WhatsApp inbox '.(int)$event['id'].': '.$code.' ('.get_class($error).')');
    }

    private function incoming(array $event, array $account): void
    {
        $message = json_decode($event['payload'], true, 512, JSON_THROW_ON_ERROR);
        $timestamp = (int)$message['timestamp'];
        if ($timestamp > time()+300 || $timestamp < 1) {
            $this->db->run("UPDATE wa_inbox SET state='failed',error_code='invalid_timestamp' WHERE id=?", [$event['id']]);
            return;
        }
        // Stable identity recovers a session insert followed by a crash before inbox mapping.
        $originMember = 'wa' . substr($event['event_key'], 0, 18);
        $session = $event['session_id'] ? $this->db->one('SELECT * FROM wa_sessions WHERE id=?', [$event['session_id']])
            : $this->db->one('SELECT * FROM wa_sessions WHERE member=?', [$originMember]);
        if ($session && !$event['session_id']) {
            $this->db->run('UPDATE wa_inbox SET session_id=? WHERE id=?', [$session['id'],$event['id']]);
        }
        if (!$session) {
            $session = $this->db->one("SELECT * FROM wa_sessions WHERE phone_number_id=? AND sender=? AND state IN ('provisioning','active','closing') ORDER BY id DESC LIMIT 1", [$event['phone_number_id'],$message['from']]);
            if ($session && ($session['state']==='closing' || $timestamp >= (int)$session['last_inbound']+86400)) {
                if ($session['chat_id']) { $this->close($session, 'window_expired'); }
                else { $this->db->run("UPDATE wa_sessions SET state='closed',closed_at=?,close_reason='window_expired' WHERE id=?", [time(),$session['id']]); }
                $session=null;
            }
            if ($session && $session['state']==='active' && !$this->db->one('SELECT chat_id FROM vicidial_live_chats WHERE chat_id=?', [$session['chat_id']])) {
                $this->db->run("UPDATE wa_sessions SET state='closed',closed_at=?,close_reason='agent_closed' WHERE id=?", [time(),$session['id']]);
                $session=null;
            }
            if (!$session) {
                if (Protocol::expired($timestamp, time())) {
                    $this->db->run("UPDATE wa_inbox SET state='expired',error_code='stale_callback' WHERE id=?", [$event['id']]);
                    return;
                }
                $previous = $this->db->one('SELECT last_inbound FROM wa_sessions WHERE phone_number_id=? AND sender=? ORDER BY id DESC LIMIT 1', [$event['phone_number_id'],$message['from']]);
                if ($previous && $timestamp < (int)$previous['last_inbound']) {
                    $this->db->run("UPDATE wa_inbox SET state='expired',error_code='out_of_order_closed_session' WHERE id=?", [$event['id']]);
                    return;
                }
                $name = Protocol::customerName($message['_name'] ?? 'WhatsApp customer');
                // The singleton worker serializes session creation. A stable member key
                // makes the two writes recoverable on nontransactional storage engines.
                $this->db->insert('wa_sessions', ['did_id'=>$account['did_id'],'phone_number_id'=>$event['phone_number_id'],
                    'sender'=>$message['from'],'group_id'=>$account['group_id'],'member'=>$originMember,
                    'member_name'=>$name,'last_inbound'=>$timestamp,'created_at'=>time()]);
                $id=$this->db->link->insert_id;
                $this->db->run('UPDATE wa_inbox SET session_id=? WHERE id=?', [$id,$event['id']]);
                $session=$this->db->one('SELECT * FROM wa_sessions WHERE id=?', [$id]);
            } else {
                $this->db->run('UPDATE wa_inbox SET session_id=? WHERE id=?', [$session['id'],$event['id']]);
            }
        }
        if (!in_array($session['state'], ['provisioning','active'], true)) {
            if ($session['state']==='closing') { $this->close($session,$session['close_reason'] ?: 'window_expired'); }
            $this->db->run("UPDATE wa_inbox SET state='closed',error_code='session_closed' WHERE id=?", [$event['id']]);
            return;
        }
        $this->db->run('UPDATE wa_sessions SET last_inbound=GREATEST(last_inbound,?) WHERE id=?', [$timestamp,$session['id']]);
        $session['last_inbound']=max($timestamp,(int)$session['last_inbound']);
        if ($session['state']==='provisioning') { $session=$this->provision($session,$account); }
        $live = $this->db->one('SELECT status FROM vicidial_live_chats WHERE chat_id=?', [$session['chat_id']]);
        if (!$live) {
            $this->db->run("UPDATE wa_inbox SET state='closed',error_code='chat_closed' WHERE id=?", [$event['id']]);
            return;
        }
        $participant=$this->db->one("SELECT * FROM vicidial_chat_participants WHERE chat_id=? AND chat_member=? AND vd_agent='N'", [$session['chat_id'],$session['member']]);
        if (!$participant) {
            $this->db->run("UPDATE wa_inbox SET state='closed',error_code='participant_removed' WHERE id=?", [$event['id']]);
            return;
        }
        if (($participant['user_status'] ?? '') === 'blocked') {
            $this->db->run("UPDATE wa_inbox SET state='blocked',error_code='customer_blocked' WHERE id=?", [$event['id']]);
            return;
        }
        if (Protocol::expired((int)$session['last_inbound'],time())) {
            $this->close($session,'window_expired');
            return;
        }
        if ($live['status'] !== 'LIVE') {
            $this->db->run("UPDATE wa_inbox SET state='waiting',next_attempt=? WHERE id=?", [time()+2,$event['id']]);
            return;
        }
        // Do not let a newly arrived message overtake an older buffered/retrying one.
        if ($this->db->one("SELECT id FROM wa_inbox WHERE session_id=? AND id<? AND kind='message' AND state IN ('pending','retry','waiting') LIMIT 1", [$session['id'],$event['id']])) {
            $this->db->run("UPDATE wa_inbox SET state='waiting',next_attempt=? WHERE id=?", [time()+1,$event['id']]);
            return;
        }
        $text=Protocol::messageText($message);
        $html=Protocol::customerHtml($text);
        $mediaError=null;
        if (Media::supports($message) && $this->media) {
            try { $html=$this->media->html($message,$account); }
            catch (MediaFailure $e) {
                if ($e->retryable && (int)$event['attempts']<7) {
                    $this->db->run("UPDATE wa_inbox SET state='retry',attempts=attempts+1,next_attempt=?,error_code=? WHERE id=?",
                        [time()+30,$e->reason,$event['id']]);
                    return;
                }
                $mediaError=$e->reason;
                $caption=$message[$message['type']]['caption'] ?? '';
                $html=Protocol::customerHtml('[WhatsApp '.$message['type'].' unavailable: '.$e->reason.']'.($caption!==''?"\n".$caption:''));
            }
        }
        $this->db->nativeInsert('inbox:'.$event['id'], 'vicidial_chat_log', [
            'chat_id'=>$session['chat_id'], 'message'=>$html,
            'poster'=>$session['member'],'chat_member_name'=>$session['member_name'],'chat_level'=>0,
            'message_time'=>$this->db->timestamp($timestamp)], 'vicidial_chat_log_archive');
        $this->db->run("UPDATE wa_inbox SET state=?,error_code=? WHERE id=?", [$mediaError?'failed':'done',$mediaError,$event['id']]);
    }

    private function provision(array $session, array $account): array
    {
        $id=(int)$session['id'];
        if (!$session['lead_id']) {
            $operation=$this->db->one('SELECT operation_key FROM wa_vici_operations WHERE operation_key=?', ['lead:'.$id]);
            $lead=$operation ? null : $this->db->one('SELECT lead_id FROM vicidial_list WHERE phone_number=? ORDER BY entry_date DESC LIMIT 1', [$session['sender']]);
            $leadId=$lead ? (int)$lead['lead_id'] : $this->db->nativeInsert('lead:'.$id, 'vicidial_list', [
                'status'=>'WCHAT','first_name'=>Protocol::customerName(html_entity_decode($session['member_name'], ENT_QUOTES | ENT_HTML5, 'UTF-8'),30), 'last_name'=>'',
                'email'=>'','list_id'=>$account['list_id'],'phone_number'=>$session['sender'],
                'security_phrase'=>$session['group_id'],'entry_date'=>$this->db->timestamp((int)$session['created_at'])]);
            $this->db->run('UPDATE wa_sessions SET lead_id=? WHERE id=?', [$leadId,$id]);
            $session['lead_id']=$leadId;
        }
        if (!$session['chat_id']) {
            $chatId=$this->db->nativeInsert('chat:'.$id,'vicidial_live_chats', [
                'status'=>'WAITING','chat_creator'=>'NONE','group_id'=>$session['group_id'],
                'lead_id'=>$session['lead_id'],'chat_start_time'=>$this->db->timestamp((int)$session['created_at'])], 'vicidial_chat_archive');
            $this->db->run('UPDATE wa_sessions SET chat_id=? WHERE id=?', [$chatId,$id]);
            $session['chat_id']=$chatId;
        }
        // May already be archived after a worker crash; never recreate an agent-closed chat.
        if (!$this->db->one('SELECT chat_id FROM vicidial_live_chats WHERE chat_id=?', [$session['chat_id']])) {
            $this->db->run("UPDATE wa_sessions SET state='closed',closed_at=?,close_reason='agent_closed' WHERE id=?", [time(),$id]);
            $session['state']='closed'; return $session;
        }
        $this->db->nativeInsert('info:'.$id,'vicidial_chat_log',[
            'chat_id'=>$session['chat_id'],'poster'=>$session['member'],'chat_member_name'=>'WhatsApp',
            'message_time'=>$this->db->timestamp((int)$session['created_at']),'chat_level'=>1,
            'message'=>Protocol::customerHtml('WhatsApp | Receiving number: +'.ltrim($account['did_pattern'],'+'))
        ],'vicidial_chat_log_archive');
        if (!$this->db->one('SELECT chat_member FROM vicidial_chat_participants WHERE chat_id=? AND chat_member=?', [$session['chat_id'],$session['member']])) {
            $this->db->insert('vicidial_chat_participants', ['chat_id'=>$session['chat_id'],'chat_member'=>$session['member'],
                'chat_member_name'=>$session['member_name'],'ping_date'=>$this->db->timestamp(time()),'vd_agent'=>'N']);
        }
        $this->db->run("UPDATE vicidial_list SET status='WCHAT' WHERE lead_id=?", [$session['lead_id']]);
        $this->db->run("UPDATE wa_sessions SET state='active' WHERE id=?", [$id]);
        $session['state']='active'; return $session;
    }

    private function deliver(array $accounts, int $limit): void
    {
        $rows=$this->db->all("SELECT o.*,s.phone_number_id,s.sender,s.last_inbound,s.state AS session_state,s.close_reason,c.chat_id AS live_chat_id
            FROM wa_outbox o JOIN wa_sessions s ON s.id=o.session_id
            LEFT JOIN vicidial_live_chats c ON c.chat_id=s.chat_id
            WHERE o.state IN ('pending','retry') AND o.next_attempt<=?
            AND NOT EXISTS (SELECT 1 FROM wa_outbox prior WHERE prior.session_id=o.session_id AND prior.id<o.id
                AND prior.state IN ('pending','retry','sending','uncertain')) ORDER BY o.id LIMIT $limit", [time()]);
        foreach ($rows as $row) {
            // A newer customer event may be behind this tick's inbox batch or
            // retrying. Decide this outbound message only after processing it.
            if ($this->db->one("SELECT id FROM wa_inbox WHERE phone_number_id=? AND sender=?
                AND state IN ('pending','retry','waiting') AND message_timestamp>? AND message_timestamp>? AND message_timestamp<=? LIMIT 1",
                [$row['phone_number_id'],$row['sender'],$row['last_inbound'],time()-86400,time()+300])) {
                continue;
            }
            if (Protocol::expired((int)$row['last_inbound'],time()) || $row['close_reason']==='window_expired') {
                $session=$this->db->one('SELECT * FROM wa_sessions WHERE id=?',[$row['session_id']]);
                if ($session && $session['state']!=='closed') { $this->close($session,'window_expired'); }
                $this->db->run("UPDATE wa_outbox SET state='expired',error_code='window_expired' WHERE id=?", [$row['id']]);
                continue;
            }
            if (!isset($accounts[$row['phone_number_id']])) { continue; }
            if ($row['live_chat_id']===null && $row['session_state']!=='closed') {
                $this->db->run("UPDATE wa_sessions SET state='closed',closed_at=?,close_reason='agent_closed' WHERE id=?",[time(),$row['session_id']]);
            }
            // Keep each conversation ordered. An uncertain earlier send requires reconciliation.
            if ($this->db->one("SELECT id FROM wa_outbox WHERE session_id=? AND id<? AND state IN ('pending','retry','sending','uncertain') LIMIT 1", [$row['session_id'],$row['id']])) { continue; }
            $this->db->run("UPDATE wa_outbox SET state='sending',attempts=attempts+1 WHERE id=?", [$row['id']]);
            try { $result=$this->sender->send($accounts[$row['phone_number_id']],$row['sender'],$row['body'],(int)$row['id']); }
            catch (\Throwable $e) { $result=['state'=>'uncertain','error'=>'send_exception']; }
            $state=$result['state'];
            if ($state==='retry' && (int)$row['attempts']>=7) { $state='failed'; }
            $delay=min(300, 5 * (2 ** min(6,(int)$row['attempts'])));
            $notice=in_array($state,['failed','uncertain'],true)?$state:null;
            $this->db->run('UPDATE wa_outbox SET state=?,wamid=?,error_code=?,next_attempt=?,sent_at=?,notice_pending=? WHERE id=?',
                [$state,$result['wamid'] ?? null,$result['error'] ?? null,time()+$delay,$state==='accepted'?time():null,$notice,$row['id']]);
            if ($notice!==null) { $this->outboundNotice((int)$row['id']); }
            $this->heartbeat();
        }
    }

    private function status(array $event): ?int
    {
        $status=json_decode($event['payload'],true,512,JSON_THROW_ON_ERROR);
        $row=$this->db->one('SELECT o.* FROM wa_outbox o JOIN wa_sessions s ON s.id=o.session_id WHERE o.wamid=? AND s.phone_number_id=?', [$status['id'],$event['phone_number_id']]);
        if (!$row && preg_match('/^wa:([0-9]+)$/D', $status['biz_opaque_callback_data'] ?? '', $match)) {
            $row=$this->db->one('SELECT o.* FROM wa_outbox o JOIN wa_sessions s ON s.id=o.session_id WHERE o.id=? AND s.phone_number_id=? AND s.sender=?', [$match[1],$event['phone_number_id'],$status['recipient_id'] ?? '']);
        }
        $noticeId=null;
        if ($row) {
            $delivery=Protocol::delivery($row['delivery_status'] ?? '',$status['status']);
            $state=$delivery==='failed'?'failed':($delivery!==''?'accepted':$row['state']);
            $error=$delivery==='failed'?'meta_'.(int)($status['errors'][0]['code'] ?? 0):null;
            $notice=$state==='failed' && $row['state']!=='failed'?'failed':$row['notice_pending'];
            $this->db->run('UPDATE wa_outbox SET wamid=?,delivery_status=?,state=?,error_code=?,notice_pending=? WHERE id=?', [$status['id'],$delivery,$state,$error,$notice,$row['id']]);
            if ($notice!==null) { $noticeId=(int)$row['id']; }
        }
        // Unrelated template sends are legitimate; they do not create customer chats.
        $this->db->run("UPDATE wa_inbox SET state='done',error_code=NULL WHERE id=?", [$event['id']]);
        return $noticeId;
    }

    private function outboundNotice(int $id): void
    {
        // Same private customer-side log mechanism as the receiving-number note.
        // Keep the chat present while inserting; do not recreate archived chats.
        $this->db->run('LOCK TABLES wa_outbox WRITE,wa_sessions READ,vicidial_live_chats READ,vicidial_chat_log WRITE,vicidial_chat_log_archive READ,wa_vici_operations WRITE');
        try {
            $row=$this->db->one('SELECT * FROM wa_outbox WHERE id=?',[$id]);
            if (!$row || $row['notice_pending']===null) { return; }
            $session=$this->db->one('SELECT * FROM wa_sessions WHERE id=?',[$row['session_id']]);
            if ($session && $session['chat_id'] && $this->db->one('SELECT chat_id FROM vicidial_live_chats WHERE chat_id=?',[$session['chat_id']])) {
                $text=$row['notice_pending']==='uncertain'
                    ? "Delivery of WhatsApp reply #$id is uncertain. It may already have been sent. Later replies are paused until its delivery status is resolved."
                    : "WhatsApp reply #$id could not be delivered. Automatic retries have stopped.";
                if ($row['error_code']) { $text.=' Error: '.$row['error_code'].'.'; }
                $this->db->nativeInsert('outbox-notice:'.$id.':'.$row['notice_pending'],'vicidial_chat_log',[
                    'chat_id'=>$session['chat_id'],'poster'=>$session['member'],'chat_member_name'=>'WhatsApp',
                    'message_time'=>$this->db->timestamp(time()),'chat_level'=>1,'message'=>Protocol::customerHtml($text)
                ],'vicidial_chat_log_archive',true);
            }
            $this->db->run('UPDATE wa_outbox SET notice_pending=NULL WHERE id=?',[$id]);
        } finally { $this->db->run('UNLOCK TABLES'); }
    }

    public function close(array $session, string $reason, bool $onlyIfExpired = false): void
    {
        $chatColumns=array_intersect_key($this->db->columns('vicidial_live_chats'),$this->db->columns('vicidial_chat_archive'));
        $logColumns=array_intersect_key($this->db->columns('vicidial_chat_log'),$this->db->columns('vicidial_chat_log_archive'));
        $optional=$this->db->one("SHOW TABLES LIKE 'chat_id_lead'") !== null;
        $this->db->run('LOCK TABLES vicidial_live_chats WRITE,vicidial_chat_archive WRITE,vicidial_chat_log WRITE,vicidial_chat_log_archive WRITE,vicidial_chat_participants WRITE,vicidial_list WRITE,wa_sessions WRITE,wa_inbox WRITE,wa_outbox WRITE,wa_vici_operations WRITE,vicidial_users READ' . ($optional?',chat_id_lead WRITE':''));
        try {
            $session=$this->db->one('SELECT * FROM wa_sessions WHERE id=?',[$session['id']]);
            if (!$session || $session['state']==='closed') { return; }
            // A webhook may have arrived after the maintenance batch was read.
            // Recheck under the inbox lock before committing customer departure.
            if ($onlyIfExpired && (!Protocol::expired((int)$session['last_inbound'],time()) || $this->db->one(
                "SELECT id FROM wa_inbox WHERE phone_number_id=? AND sender=? AND state IN ('pending','retry','waiting')
                    AND message_timestamp>? AND message_timestamp>? AND message_timestamp<=? LIMIT 1",
                [$session['phone_number_id'],$session['sender'],$session['last_inbound'],time()-86400,time()+300]))) { return; }
            $this->db->run("UPDATE wa_sessions SET state='closing',close_reason=?,closed_at=COALESCE(closed_at,?) WHERE id=?",[$reason,time(),$session['id']]);
            $session=$this->db->one('SELECT * FROM wa_sessions WHERE id=?',[$session['id']]);
            $chat=$this->db->one('SELECT * FROM vicidial_live_chats WHERE chat_id=?',[$session['chat_id']]);
            if ($chat && $chat['status']==='WAITING' && $chat['chat_creator']==='NONE') {
                // Same unclaimed-chat DROP behavior as customer_chat_functions.php.
                $row=array_intersect_key($chat,$chatColumns); $row['status']='DROP';
                if (!$this->db->one('SELECT chat_id FROM vicidial_chat_archive WHERE chat_id=?',[$session['chat_id']])) { $this->db->insert('vicidial_chat_archive',$row); }
                $cols=implode(',',array_map([Database::class,'ident'],array_keys($logColumns)));
                $pk=Database::ident($this->logKey);
                $this->db->run("INSERT INTO vicidial_chat_log_archive ($cols) SELECT $cols FROM vicidial_chat_log WHERE chat_id=? ON DUPLICATE KEY UPDATE $pk=VALUES($pk)",[$session['chat_id']]);
                $this->db->run("UPDATE vicidial_list SET status='CDROP' WHERE lead_id=? AND status='WCHAT'",[$session['lead_id']]);
                $this->db->run('DELETE FROM vicidial_chat_log WHERE chat_id=?',[$session['chat_id']]);
                $this->db->run('DELETE FROM vicidial_live_chats WHERE chat_id=?',[$session['chat_id']]);
            } elseif ($chat) {
                // Agent-owned chats are left intact. Journal the private notice
                // before removing the customer so a partial departure is replayable.
                $agent=$this->db->one('SELECT full_name FROM vicidial_users WHERE user=?',[$chat['chat_creator']]);
                $participant=$this->db->one("SELECT chat_member FROM vicidial_chat_participants WHERE chat_id=? AND chat_member=? AND vd_agent='N'",[$session['chat_id'],$session['member']]);
                if ($agent && $participant) {
                    $this->db->nativeInsert('leave:'.$session['id'],'vicidial_chat_log',[
                        'chat_id'=>$session['chat_id'],'poster'=>$chat['chat_creator'],
                        'chat_member_name'=>$agent['full_name'],'message_time'=>$this->db->timestamp((int)$session['closed_at']),
                        'message'=>$session['member_name'].' has left chat','chat_level'=>1
                    ],'vicidial_chat_log_archive',true);
                }
            }
            // Never remove the agent's participant or dispose their chat.
            $this->db->run("DELETE FROM vicidial_chat_participants WHERE chat_id=? AND chat_member=? AND vd_agent='N'",[$session['chat_id'],$session['member']]);
            if ($optional) { $this->db->run('UPDATE chat_id_lead SET status=NULL WHERE chat_id=?',[$session['chat_id']]); }
            $terminal=$reason==='window_expired'?'expired':'closed';
            $this->db->run("UPDATE wa_inbox SET state=?,error_code=? WHERE session_id=? AND state IN ('waiting','retry','pending')",[$terminal,$reason,$session['id']]);
            $this->db->run("UPDATE wa_outbox SET state=?,error_code=? WHERE session_id=? AND state IN ('pending','retry')",[$terminal,$reason,$session['id']]);
            // Last write: if interrupted, recover() completes this departure once.
            $this->db->run("UPDATE wa_sessions SET state='closed' WHERE id=?",[$session['id']]);
        } finally { $this->db->run('UNLOCK TABLES'); }
    }
}
