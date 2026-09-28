<?php
namespace WaChat;

final class Status
{
    public static function report(Database $db): string
    {
        $lines=['wa_sessions'];
        foreach ($db->all('SELECT state,COUNT(*) AS n FROM wa_sessions GROUP BY state ORDER BY state') as $row) {
            $lines[]='  '.$row['state'].': '.$row['n'];
        }
        $groups=['message'=>[],'status'=>[]];
        foreach ($db->all('SELECT kind,state,COUNT(*) AS n FROM wa_inbox GROUP BY kind,state ORDER BY kind,state') as $row) {
            $groups[$row['kind']][$row['state']]=(int)$row['n'];
        }
        $total=array_sum(array_map('array_sum',$groups));
        $lines[]="wa_inbox: $total retained webhook events (includes processed history)";
        foreach (['message'=>'Customer messages','status'=>'Delivery notifications'] as $kind=>$label) {
            $lines[]='  '.$label.': '.array_sum($groups[$kind]);
            foreach ($groups[$kind] as $state=>$n) { $lines[]="    $state: $n"; }
        }
        $lines[]='wa_outbox (accepted means accepted by the API; delivery is tracked separately)';
        foreach ($db->all('SELECT state,delivery_status,COUNT(*) AS n FROM wa_outbox GROUP BY state,delivery_status ORDER BY state,delivery_status') as $row) {
            $delivery=$row['delivery_status'] ?: 'unconfirmed';
            $lines[]='  '.$row['state'].' / '.$delivery.': '.$row['n'];
        }
        $lines[]='Recent outbound failures/uncertain sends (IDs only; no credentials or message text):';
        foreach ($db->all("SELECT id,session_id,state,attempts,error_code FROM wa_outbox WHERE state IN ('failed','uncertain') ORDER BY id DESC LIMIT 20") as $row) {
            $lines[]=json_encode($row,JSON_THROW_ON_ERROR);
        }
        $lines[]='Recent inbox errors (kind distinguishes customer messages from delivery notifications):';
        foreach ($db->all("SELECT id,kind,state,attempts,error_code FROM wa_inbox WHERE state IN ('retry','failed') ORDER BY id DESC LIMIT 20") as $row) {
            $lines[]=json_encode($row,JSON_THROW_ON_ERROR);
        }
        return implode(PHP_EOL,$lines).PHP_EOL;
    }
}
