<?php
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require __DIR__.'/bootstrap.php';
try {
    [$db,$config]=wa_bootstrap();
    foreach (['wa_sessions','wa_inbox','wa_outbox'] as $table) {
        echo $table.PHP_EOL;
        foreach ($db->all("SELECT state,COUNT(*) AS n FROM $table GROUP BY state ORDER BY state") as $row) {
            echo '  '.$row['state'].': '.$row['n'].PHP_EOL;
        }
    }
    echo "Recent outbound failures/uncertain sends (IDs only; no credentials or message text):\n";
    foreach ($db->all("SELECT id,session_id,state,attempts,error_code FROM wa_outbox WHERE state IN ('failed','uncertain') ORDER BY id DESC LIMIT 20") as $row) {
        echo json_encode($row,JSON_THROW_ON_ERROR).PHP_EOL;
    }
    echo "Recent inbound errors:\n";
    foreach ($db->all("SELECT id,state,attempts,error_code FROM wa_inbox WHERE state IN ('retry','failed') ORDER BY id DESC LIMIT 20") as $row) {
        echo json_encode($row,JSON_THROW_ON_ERROR).PHP_EOL;
    }
} catch (Throwable $e) {
    fwrite(STDERR,"Status unavailable (".get_class($e).").\n"); exit(1);
}
