<?php
namespace WaChat;

/** One durable cursor across the live/archive copies of the native message IDs. */
final class ReplyPoller
{
    private Database $db;
    private string $key;
    public function __construct(Database $db) { $this->db=$db; $this->key=$db->primary('vicidial_chat_log'); }

    public function collect(int $limit): void
    {
        $pk=Database::ident($this->key);
        $saved=$this->db->one("SELECT last_id FROM wa_worker_state WHERE name='chat_log'");
        $cursor=$saved ? (int)$saved['last_id'] : null;
        $rows=[];
        // Brief shared locks prevent an archive move between the two reads from
        // hiding a final reply. No HTTP or outbox writes while holding these locks.
        $this->db->run('LOCK TABLES vicidial_chat_log READ,vicidial_chat_log_archive READ,wa_sessions READ');
        try {
            // A native chat may have been created just before its bridge mapping
            // failed. Do not skip its replies while that provisioning is retried.
            if ($this->db->one("SELECT id FROM wa_sessions WHERE state='provisioning' LIMIT 1")) { return; }
            if ($cursor===null) {
                // Upgrade bootstrap: include existing WhatsApp replies without
                // replaying all unrelated historical chat data from ID zero.
                $first=null; $maximum=0;
                foreach (['vicidial_chat_log','vicidial_chat_log_archive'] as $table) {
                    $n=$this->db->one("SELECT MIN($pk) AS n FROM $table WHERE chat_id IN
                        (SELECT chat_id FROM wa_sessions WHERE state IN ('active','provisioning','closing') OR close_reason='agent_closed')")['n'];
                    if ($n!==null) { $first=$first===null?(int)$n:min($first,(int)$n); }
                    $maximum=max($maximum,(int)$this->db->one("SELECT MAX($pk) AS n FROM $table")['n']);
                }
                $cursor=$first===null?$maximum:max(0,$first-1);
            }
            foreach (['vicidial_chat_log','vicidial_chat_log_archive'] as $table) {
                foreach ($this->db->all("SELECT * FROM $table WHERE $pk>? ORDER BY $pk LIMIT $limit",[$cursor]) as $row) {
                    // Prefer the live copy if both exist during archival.
                    $rows[(int)$row[$this->key]]=$rows[(int)$row[$this->key]] ?? $row;
                }
            }
        } finally { $this->db->run('UNLOCK TABLES'); }
        ksort($rows,SORT_NUMERIC);
        $rows=array_slice($rows,0,$limit,true);
        if ($rows) {
            $chatIds=array_values(array_unique(array_column($rows,'chat_id')));
            $marks=implode(',',array_fill(0,count($chatIds),'?'));
            $sessions=$this->db->all("SELECT * FROM wa_sessions WHERE chat_id IN ($marks)
                AND (state IN ('active','provisioning','closing') OR close_reason='agent_closed')",$chatIds);
            $byChat=array_column($sessions,null,'chat_id');
            $posters=[];
            foreach ($rows as $row) {
                if (isset($byChat[$row['chat_id']]) && (int)$row['chat_level']===0) { $posters[]=$row['poster']; }
            }
            $agents=[];
            if ($posters) {
                $posters=array_values(array_unique($posters)); $marks=implode(',',array_fill(0,count($posters),'?'));
                $agents=array_column($this->db->all("SELECT user FROM vicidial_users WHERE user IN ($marks)",$posters),'user','user');
            }
            foreach ($rows as $id=>$row) {
                $session=$byChat[$row['chat_id']] ?? null;
                if (!$session || (int)$row['chat_level']!==0 || $row['poster']===$session['member']
                    || $row['chat_member_name']==='COUNTRY AND IP ADDRESS' || !isset($agents[$row['poster']])) { continue; }
                foreach (Protocol::chunks(Protocol::whatsappText($row['message'])) as $part=>$body) {
                    $this->db->run("INSERT INTO wa_outbox (session_id,source_id,part,body,created_at) VALUES (?,?,?,?,?)
                        ON DUPLICATE KEY UPDATE source_id=VALUES(source_id)",[$session['id'],$id,$part,$body,time()]);
                }
            }
            $cursor=(int)array_key_last($rows);
        }
        // Advance only after every part is durable. Partial failures replay safely
        // through the outbox's unique session/source/part key, including on MyISAM.
        if (!$saved || $cursor!==(int)$saved['last_id']) {
            $this->db->run("INSERT INTO wa_worker_state (name,last_id) VALUES ('chat_log',?)
                ON DUPLICATE KEY UPDATE last_id=VALUES(last_id)",[$cursor]);
        }
    }
}
