<?php
namespace WaChat;

final class Accounts
{
    private Database $db;
    public function __construct(Database $db) { $this->db = $db; }

    public function all(bool $enabledOnly = true): array
    {
        // These slots are explicitly reserved for WhatsApp on participating DIDs/groups.
        $rows = $this->db->all("SELECT d.did_id,d.did_pattern,d.did_active,d.custom_two AS phone_number_id,
            g.group_id,g.active,g.group_handling,g.hold_time_option_callback_list_id AS list_id,
            g.custom_one AS token,g.custom_two AS app_secret,g.custom_three AS verify_token
            FROM vicidial_inbound_dids d JOIN vicidial_inbound_groups g ON g.group_id=d.custom_one
            WHERE g.group_handling='CHAT' AND d.custom_two<>''" .
            ($enabledOnly ? " AND d.did_active='Y' AND g.active='Y'" : ''));
        $result = [];
        foreach ($rows as $row) {
            $id = trim($row['phone_number_id']);
            if (!preg_match('/^[0-9]+$/D', $id)) {
                throw new \RuntimeException('Invalid phone-number ID in DID ' . $row['did_id']);
            }
            if (isset($result[$id])) {
                throw new \RuntimeException('Duplicate WhatsApp phone-number ID in DID configuration');
            }
            $row['phone_number_id'] = $id;
            foreach (['token','app_secret','verify_token'] as $field) {
                $row[$field] = trim($row[$field]);
                if ($row[$field] === '') {
                    throw new \RuntimeException('Missing WhatsApp ' . $field . ' for group ' . $row['group_id']);
                }
            }
            $result[$id] = $row;
        }
        return $result;
    }
}
