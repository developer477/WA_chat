<?php
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require __DIR__.'/bootstrap.php';
try {
    [$db,$config]=wa_bootstrap();
    $accounts=\WaChat\Preflight::check($db,$config);
    foreach ($accounts as $account) {
        printf("DID %s -> chat group %s; phone-number ID %s; token length %d\n",
            $account['did_pattern'],$account['group_id'],$account['phone_number_id'],strlen($account['token']));
    }
    [$directory]=(new \WaChat\Media($db,$config,new \WaChat\GraphClient($config['graph_version'])))->location();
    $writable=is_writable(is_dir($directory)?$directory:dirname($directory));
    printf("Media directory: %s; writable by current user: %s\n",$directory,$writable?'yes':'no');
    if (!$writable) { throw new RuntimeException('Grant the worker user write access to the media directory before testing attachments.'); }
    echo "Configuration/schema checks passed. This does not test Meta delivery or production chat behavior.\n";
} catch (mysqli_sql_exception $e) {
    fwrite(STDERR,"Database/schema check failed (SQL error ".$e->getCode()."). Check connection, table definitions and grants.\n"); exit(1);
} catch (Throwable $e) {
    fwrite(STDERR,$e->getMessage().PHP_EOL); exit(1);
}
