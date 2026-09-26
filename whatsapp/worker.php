<?php
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require __DIR__.'/bootstrap.php';
try {
    [$db,$config]=wa_bootstrap();
    \WaChat\Preflight::check($db,$config);
    $lock='wa_chat_worker_'.substr(hash('sha256',$db->one('SELECT DATABASE() AS db')['db']),0,24);
    if ((int)$db->one('SELECT GET_LOCK(?,0) AS acquired',[$lock])['acquired']!==1) {
        throw new RuntimeException('Another WhatsApp worker is already running');
    }
    $bridge=new \WaChat\Bridge($db,new \WaChat\GraphClient($config['graph_version']),$config);
    $bridge->recover();
    $running=true;
    if (function_exists('pcntl_async_signals')) {
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM,function() use (&$running) {$running=false;});
        pcntl_signal(SIGINT,function() use (&$running) {$running=false;});
    }
    do {
        $bridge->tick();
        if (in_array('--once',$argv,true)) { break; }
        sleep(max(1,(int)$config['poll_seconds']));
    } while ($running);
    $db->run('SELECT RELEASE_LOCK(?)',[$lock]);
} catch (Throwable $e) {
    // Avoid exception text: mysqli errors can include SQL or customer/credential data.
    fwrite(STDERR,'WhatsApp worker stopped ('.get_class($e).'). Run check.php for configuration checks.'.PHP_EOL);
    exit(1);
}
