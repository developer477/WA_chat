<?php
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require __DIR__.'/bootstrap.php';
try {
    [$db,$config]=wa_bootstrap();
    echo \WaChat\Status::report($db);
} catch (Throwable $e) {
    fwrite(STDERR,"Status unavailable (".get_class($e).").\n"); exit(1);
}
