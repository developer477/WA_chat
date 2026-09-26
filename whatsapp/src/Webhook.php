<?php
namespace WaChat;

final class Webhook
{
    private Database $db;
    private Accounts $accounts;
    public function __construct(Database $db) { $this->db=$db; $this->accounts=new Accounts($db); }

    public function receive(string $body, string $signature): void
    {
        $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($payload)) { throw new \InvalidArgumentException('Invalid envelope'); }
        $accounts = $this->accounts->all(false);
        // Authenticate before parsing customer events, and for empty/status-only batches too.
        $authenticated = false;
        foreach ($accounts as $account) {
            if (Protocol::signature($body, $signature, $account['app_secret'])) { $authenticated=true; break; }
        }
        if (!$authenticated) { throw new \UnexpectedValueException('Invalid signature'); }
        $events = Protocol::events($payload);
        foreach ($events as $event) {
            if (isset($accounts[$event['phone']]) && !Protocol::signature($body, $signature, $accounts[$event['phone']]['app_secret'])) {
                throw new \UnexpectedValueException('Signature/account mismatch');
            }
        }
        $this->db->link->begin_transaction();
        try {
            foreach ($events as $event) {
                if (!isset($accounts[$event['phone']])) { continue; }
                $this->db->run("INSERT INTO wa_inbox (event_key,phone_number_id,kind,payload,received_at) VALUES (?,?,?,?,?)
                    ON DUPLICATE KEY UPDATE event_key=VALUES(event_key)", [$event['key'],$event['phone'],$event['kind'],json_encode($event['payload'], JSON_THROW_ON_ERROR),time()]);
            }
            $this->db->link->commit();
        } catch (\Throwable $e) {
            $this->db->link->rollback();
            throw $e;
        }
    }

    public function verify(string $token): bool
    {
        foreach ($this->accounts->all(false) as $account) {
            if (hash_equals($account['verify_token'], $token)) { return true; }
        }
        return false;
    }
}
