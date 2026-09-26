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

    public function __construct(Database $db, Sender $sender, array $config)
    {
        $this->db=$db; $this->accounts=new Accounts($db); $this->sender=$sender; $this->config=$config;
        $this->logKey=$db->primary('vicidial_chat_log');
    }

    public function recover(): void
    {
        // A terminated process may have sent the HTTP request. Never resend it blindly.
        $this->db->run("UPDATE wa_outbox SET state='uncertain',error_code='worker_interrupted' WHERE state='sending'");
    }

    public function tick(): void
    {
        $accounts = $this->accounts->all();
        if ((int)($this->db->one('SELECT allow_chats FROM system_settings')['allow_chats'] ?? 0) < 1) {
            throw new \RuntimeException('VICIdial chats are disabled');
        }
        $this->heartbeat();
        $now = time();
        $limit = max(1, min(1000, (int)$this->config['batch_size']));
        $events = $this->db->all("SELECT * FROM wa_inbox WHERE state IN ('pending','retry','waiting') AND next_attempt<=? ORDER BY (state='waiting'),id LIMIT $limit", [$now]);
        foreach ($events as $event) {
            $this->heartbeat();
            try {
                if ($event['kind'] === 'status') { $this->status($event); continue; }
                if (!isset($accounts[$event['phone_number_id']])) { continue; }
                $this->incoming($event, $accounts[$event['phone_number_id']]);
            } catch (\Throwable $e) {
                // No SQL, message bodies or credentials in logs/error fields.
                $this->db->run("UPDATE wa_inbox SET state='retry',attempts=attempts+1,next_attempt=?,error_code='processing_error' WHERE id=?", [time()+30,$event['id']]);
                error_log('WhatsApp inbox ' . (int)$event['id'] . ': processing_error (' . get_class($e) . ')');
            }
        }
        $sessions = $this->db->all("SELECT * FROM wa_sessions WHERE state IN ('active','closing') ORDER BY id");
        foreach ($sessions as $session) {
            try {
                $live = $this->db->one('SELECT * FROM vicidial_live_chats WHERE chat_id=?', [$session['chat_id']]);
                // Collect from both live and archive before marking an agent-closed session closed.
                $this->collect($session);
                if ($session['state'] === 'closing') {
                    $this->close($session, $session['close_reason'] ?: 'window_expired');
                } elseif (!$live) {
                    $this->db->run("UPDATE wa_sessions SET state='closed',closed_at=?,close_reason='agent_closed' WHERE id=?", [time(),$session['id']]);
                    $this->db->run("UPDATE wa_inbox SET state='closed',error_code='agent_closed_before_delivery' WHERE session_id=? AND state='waiting'", [$session['id']]);
                } elseif (Protocol::expired((int)$session['last_inbound'], time()) && !$this->pendingInbound($session)) {
                    $this->close($session, 'window_expired');
                } else {
                    // Presence keeps this asynchronous customer available until its session ends.
                    $this->db->run("UPDATE vicidial_chat_participants SET ping_date=NOW() WHERE chat_id=? AND chat_member=? AND vd_agent='N'", [$session['chat_id'],$session['member']]);
                }
            } catch (\Throwable $e) {
                error_log('WhatsApp session ' . (int)$session['id'] . ': processing_error (' . get_class($e) . ')');
            }
        }
        $this->deliver($accounts, $limit);
    }

    private function heartbeat(): void
    {
        if (time() - $this->lastHeartbeat < 5) { return; }
        $this->lastHeartbeat = time();
        $this->db->run("UPDATE vicidial_chat_participants p JOIN wa_sessions s ON s.chat_id=p.chat_id AND s.member=p.chat_member SET p.ping_date=NOW() WHERE s.state='active' AND s.last_inbound>? AND p.vd_agent='N'", [time()-86400]);
    }

    private function pendingInbound(array $session): bool
    {
        return $this->db->one("SELECT id FROM wa_inbox WHERE phone_number_id=? AND kind='message'
            AND state IN ('pending','retry') AND JSON_UNQUOTE(JSON_EXTRACT(payload,'$.from'))=? LIMIT 1",
            [$session['phone_number_id'],$session['sender']]) !== null;
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
                $this->collect($session);
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
        if ($live['status'] !== 'LIVE') {
            $this->db->run("UPDATE wa_inbox SET state='waiting',next_attempt=? WHERE id=?", [time()+2,$event['id']]);
            return;
        }
        if (Protocol::expired((int)$session['last_inbound'],time())) {
            $this->db->run("UPDATE wa_inbox SET state='expired',error_code='window_expired' WHERE id=?", [$event['id']]);
            return;
        }
        // Do not let a newly arrived message overtake an older buffered/retrying one.
        if ($this->db->one("SELECT id FROM wa_inbox WHERE session_id=? AND id<? AND kind='message' AND state IN ('pending','retry','waiting') LIMIT 1", [$session['id'],$event['id']])) {
            $this->db->run("UPDATE wa_inbox SET state='waiting',next_attempt=? WHERE id=?", [time()+1,$event['id']]);
            return;
        }
        $text=Protocol::messageText($message);
        $this->db->nativeInsert('inbox:'.$event['id'], 'vicidial_chat_log', [
            'chat_id'=>$session['chat_id'], 'message'=>Protocol::customerHtml($text),
            'poster'=>$session['member'],'chat_member_name'=>$session['member_name'],'chat_level'=>0,
            'message_time'=>$this->db->timestamp($timestamp)], 'vicidial_chat_log_archive');
        $this->db->run("UPDATE wa_inbox SET state='done',error_code=NULL WHERE id=?", [$event['id']]);
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
        if (!$this->db->one('SELECT chat_member FROM vicidial_chat_participants WHERE chat_id=? AND chat_member=?', [$session['chat_id'],$session['member']])) {
            $this->db->insert('vicidial_chat_participants', ['chat_id'=>$session['chat_id'],'chat_member'=>$session['member'],
                'chat_member_name'=>$session['member_name'],'ping_date'=>$this->db->timestamp(time()),'vd_agent'=>'N']);
        }
        $this->db->run("UPDATE vicidial_list SET status='WCHAT' WHERE lead_id=?", [$session['lead_id']]);
        $this->db->run("UPDATE wa_sessions SET state='active' WHERE id=?", [$id]);
        $session['state']='active'; return $session;
    }

    private function collect(array $session): void
    {
        $pk=Database::ident($this->logKey);
        // Read live first then archive so a concurrent move to archive cannot hide a final reply.
        foreach (['vicidial_chat_log','vicidial_chat_log_archive'] as $table) {
            $rows=$this->db->all("SELECT * FROM $table WHERE chat_id=? AND chat_level='0' AND poster<>?
                AND chat_member_name<>'COUNTRY AND IP ADDRESS' ORDER BY $pk", [$session['chat_id'],$session['member']]);
            foreach ($rows as $row) {
                // Only genuine VICIdial agent posters, including agents removed after transfer/closure.
                if (!$this->db->one('SELECT user FROM vicidial_users WHERE user=?', [$row['poster']])) { continue; }
                $text=Protocol::whatsappText($row['message']);
                foreach (Protocol::chunks($text) as $part=>$body) {
                    if ($this->db->one('SELECT id FROM wa_outbox WHERE session_id=? AND source_id=? AND part=?', [$session['id'],$row[$this->logKey],$part])) { continue; }
                    $this->db->run("INSERT INTO wa_outbox (session_id,source_id,part,body,created_at) VALUES (?,?,?,?,?)
                        ON DUPLICATE KEY UPDATE source_id=VALUES(source_id)", [$session['id'],$row[$this->logKey],$part,$body,time()]);
                }
            }
        }
    }

    private function deliver(array $accounts, int $limit): void
    {
        $this->db->run("UPDATE wa_outbox o JOIN wa_sessions s ON s.id=o.session_id SET o.state='expired',o.error_code='window_expired' WHERE o.state IN ('pending','retry') AND (s.last_inbound<=? OR s.close_reason='window_expired')", [time()-86400]);
        $rows=$this->db->all("SELECT o.*,s.phone_number_id,s.sender,s.last_inbound,s.state AS session_state,s.close_reason
            FROM wa_outbox o JOIN wa_sessions s ON s.id=o.session_id
            WHERE o.state IN ('pending','retry') AND o.next_attempt<=?
            AND NOT EXISTS (SELECT 1 FROM wa_outbox prior WHERE prior.session_id=o.session_id AND prior.id<o.id
                AND prior.state IN ('pending','retry','sending','uncertain')) ORDER BY o.id LIMIT $limit", [time()]);
        foreach ($rows as $row) {
            if (Protocol::expired((int)$row['last_inbound'],time()) || $row['close_reason']==='window_expired') {
                $this->db->run("UPDATE wa_outbox SET state='expired',error_code='window_expired' WHERE id=?", [$row['id']]);
                continue;
            }
            if (!isset($accounts[$row['phone_number_id']])) { continue; }
            // Keep each conversation ordered. An uncertain earlier send requires reconciliation.
            if ($this->db->one("SELECT id FROM wa_outbox WHERE session_id=? AND id<? AND state IN ('pending','retry','sending','uncertain') LIMIT 1", [$row['session_id'],$row['id']])) { continue; }
            $this->heartbeat();
            $this->db->run("UPDATE wa_outbox SET state='sending',attempts=attempts+1 WHERE id=?", [$row['id']]);
            try { $result=$this->sender->send($accounts[$row['phone_number_id']],$row['sender'],$row['body'],(int)$row['id']); }
            catch (\Throwable $e) { $result=['state'=>'uncertain','error'=>'send_exception']; }
            $state=$result['state'];
            if ($state==='retry' && (int)$row['attempts']>=7) { $state='failed'; }
            $delay=min(300, 5 * (2 ** min(6,(int)$row['attempts'])));
            $this->db->run('UPDATE wa_outbox SET state=?,wamid=?,error_code=?,next_attempt=?,sent_at=? WHERE id=?',
                [$state,$result['wamid'] ?? null,$result['error'] ?? null,time()+$delay,$state==='accepted'?time():null,$row['id']]);
        }
    }

    private function status(array $event): void
    {
        $status=json_decode($event['payload'],true,512,JSON_THROW_ON_ERROR);
        $row=$this->db->one('SELECT o.* FROM wa_outbox o JOIN wa_sessions s ON s.id=o.session_id WHERE o.wamid=? AND s.phone_number_id=?', [$status['id'],$event['phone_number_id']]);
        if (!$row && preg_match('/^wa:([0-9]+)$/D', $status['biz_opaque_callback_data'] ?? '', $match)) {
            $row=$this->db->one('SELECT o.* FROM wa_outbox o JOIN wa_sessions s ON s.id=o.session_id WHERE o.id=? AND s.phone_number_id=? AND s.sender=?', [$match[1],$event['phone_number_id'],$status['recipient_id'] ?? '']);
        }
        if ($row) {
            $delivery=Protocol::delivery($row['delivery_status'] ?? '',$status['status']);
            $state=$delivery==='failed'?'failed':($delivery!==''?'accepted':$row['state']);
            $error=$delivery==='failed'?'meta_'.(int)($status['errors'][0]['code'] ?? 0):null;
            $this->db->run('UPDATE wa_outbox SET wamid=?,delivery_status=?,state=?,error_code=? WHERE id=?', [$status['id'],$delivery,$state,$error,$row['id']]);
        }
        // Unrelated template sends are legitimate; they do not create customer chats.
        $this->db->run("UPDATE wa_inbox SET state='done',error_code=NULL WHERE id=?", [$event['id']]);
    }

    public function close(array $session, string $reason): void
    {
        $this->db->run("UPDATE wa_sessions SET state='closing',close_reason=? WHERE id=?", [$reason,$session['id']]);
        // Snapshot/archive and delete under native table locks (also works on MyISAM).
        $chatColumns=array_intersect_key($this->db->columns('vicidial_live_chats'),$this->db->columns('vicidial_chat_archive'));
        $logColumns=array_intersect_key($this->db->columns('vicidial_chat_log'),$this->db->columns('vicidial_chat_log_archive'));
        $optional=$this->db->one("SHOW TABLES LIKE 'chat_id_lead'") !== null;
        $this->db->run('LOCK TABLES vicidial_live_chats WRITE,vicidial_chat_archive WRITE,vicidial_chat_log WRITE,vicidial_chat_log_archive WRITE,vicidial_chat_participants WRITE,vicidial_list WRITE,wa_sessions WRITE,wa_inbox WRITE,wa_outbox WRITE' . ($optional?',chat_id_lead WRITE':''));
        try {
            $chat=$this->db->one('SELECT * FROM vicidial_live_chats WHERE chat_id=?', [$session['chat_id']]);
            if ($chat) {
                $status=$chat['status']==='WAITING' && $chat['chat_creator']==='NONE'?'DROP':$this->config['archive_complete_status'];
                $row=array_intersect_key($chat,$chatColumns); $row['status']=$status;
                if (!$this->db->one('SELECT chat_id FROM vicidial_chat_archive WHERE chat_id=?', [$session['chat_id']])) { $this->db->insert('vicidial_chat_archive',$row); }
                $cols=implode(',',array_map([Database::class,'ident'],array_keys($logColumns)));
                $pk=Database::ident($this->logKey);
                // Copy before deleting; on recovery a previously copied row is a no-op.
                $this->db->run("INSERT INTO vicidial_chat_log_archive ($cols) SELECT $cols FROM vicidial_chat_log WHERE chat_id=? ON DUPLICATE KEY UPDATE $pk=VALUES($pk)", [$session['chat_id']]);
                if ($status==='DROP') { $this->db->run("UPDATE vicidial_list SET status='CDROP' WHERE lead_id=? AND status='WCHAT'", [$session['lead_id']]); }
                $this->db->run('DELETE FROM vicidial_chat_log WHERE chat_id=?', [$session['chat_id']]);
                $this->db->run('DELETE FROM vicidial_chat_participants WHERE chat_id=?', [$session['chat_id']]);
                if ($optional) { $this->db->run('UPDATE chat_id_lead SET status=NULL WHERE chat_id=?', [$session['chat_id']]); }
                // Delete live row last so a partial close is retried on restart.
                $this->db->run('DELETE FROM vicidial_live_chats WHERE chat_id=?', [$session['chat_id']]);
            }
            $this->db->run("UPDATE wa_sessions SET state='closed',closed_at=?,close_reason=? WHERE id=?", [time(),$reason,$session['id']]);
            $this->db->run("UPDATE wa_inbox SET state='expired',error_code='window_expired' WHERE session_id=? AND state IN ('waiting','retry','pending')", [$session['id']]);
            $this->db->run("UPDATE wa_outbox SET state='expired',error_code='window_expired' WHERE session_id=? AND state IN ('pending','retry')", [$session['id']]);
        } finally { $this->db->run('UNLOCK TABLES'); }
    }
}
