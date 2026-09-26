<?php
namespace WaChat;

final class Preflight
{
    public static function check(Database $db, array $config): array
    {
        $required=[
            'vicidial_inbound_dids'=>['did_id','did_pattern','did_active','custom_one','custom_two'],
            'vicidial_inbound_groups'=>['group_id','active','group_handling','hold_time_option_callback_list_id','custom_one','custom_two','custom_three'],
            'vicidial_list'=>['lead_id','phone_number','status','entry_date','first_name','last_name','email','list_id','security_phrase'],
            'vicidial_live_chats'=>['chat_id','status','chat_creator','group_id','lead_id','chat_start_time'],
            'vicidial_chat_archive'=>['chat_id','status','lead_id','chat_start_time'],
            'vicidial_chat_participants'=>['chat_id','chat_member','chat_member_name','vd_agent','ping_date'],
            'vicidial_chat_log'=>['chat_id','message','poster','chat_member_name','chat_level','message_time'],
            'vicidial_chat_log_archive'=>['chat_id','message','poster','chat_member_name','chat_level','message_time'],
            'vicidial_users'=>['user'],
            'wa_sessions'=>['id','state','last_inbound'], 'wa_inbox'=>['id','event_key','payload'],
            'wa_outbox'=>['id','wamid','state'], 'wa_vici_operations'=>['operation_key','target_id','row_json'],
        ];
        foreach ($required as $table=>$columns) {
            foreach ($columns as $column) {
                if (!isset($db->columns($table)[$column])) { throw new \RuntimeException("Missing $table.$column"); }
            }
        }
        foreach (['vicidial_list','vicidial_live_chats','vicidial_chat_log'] as $table) {
            $pk=$db->primary($table);
            if (strpos($db->columns($table)[$pk]['Extra'],'auto_increment')===false) {
                throw new \RuntimeException("$table requires an auto-increment primary key");
            }
        }
        if ($db->primary('vicidial_chat_log')!==$db->primary('vicidial_chat_log_archive')) {
            throw new \RuntimeException('Live/archive message keys must match');
        }
        foreach (['vicidial_live_chats'=>'vicidial_chat_archive','vicidial_chat_log'=>'vicidial_chat_log_archive'] as $live=>$archive) {
            foreach ($db->columns($live) as $name=>$column) {
                if ($live==='vicidial_chat_log' && !isset($db->columns($archive)[$name])) {
                    throw new \RuntimeException("Archive $archive lacks $name; refusing to discard native data");
                }
            }
            foreach ($db->columns($archive) as $name=>$column) {
                if (!isset($db->columns($live)[$name]) && $column['Null']==='NO' && $column['Default']===null && strpos($column['Extra'],'auto_increment')===false) {
                    throw new \RuntimeException("Archive $archive requires an unsupported field: $name");
                }
            }
        }
        foreach (['wa_sessions','wa_inbox','wa_outbox','wa_vici_operations'] as $table) {
            $status=$db->one('SHOW TABLE STATUS WHERE Name=?',[$table]);
            if (!in_array(strtolower($status['Engine'] ?? ''), ['innodb','myisam'], true)) {
                throw new \RuntimeException("$table must use MyISAM or InnoDB");
            }
        }
        $type=$db->columns('vicidial_chat_archive')['status']['Type'];
        foreach (['DROP',$config['archive_complete_status']] as $status) {
            if (!preg_match('/^[A-Z_]+$/D',$status) || (strpos($type,'enum(')===0 && strpos($type,"'".$status."'")===false)) {
                throw new \RuntimeException("Archive status $status is not supported by $type; configure archive_complete_status");
            }
        }
        $accounts=(new Accounts($db))->all();
        if (!$accounts) { throw new \RuntimeException('No enabled WhatsApp DID/chat-group mapping configured'); }
        foreach ($accounts as $account) {
            if ((int)$account['list_id']<1) { throw new \RuntimeException('Chat group needs a callback list ID'); }
            if (!preg_match('/^[a-f0-9]{32}$/iD',$account['app_secret'])) { throw new \RuntimeException('Meta App Secret must be configured on the chat group'); }
        }
        $settings=$db->one('SELECT allow_chats FROM system_settings');
        if (!$settings || (int)$settings['allow_chats']<1) { throw new \RuntimeException('VICIdial chats are disabled'); }
        return $accounts;
    }
}
