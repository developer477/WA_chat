<?php
ini_set('display_errors','0');
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
require __DIR__ . '/bootstrap.php';
try {
    [$db,$config]=wa_bootstrap();
    $webhook=new \WaChat\Webhook($db);
    $method=$_SERVER['REQUEST_METHOD'] ?? '';
    if ($method==='GET') {
        // PHP converts dots in query keys to underscores.
        if (($_GET['hub_mode'] ?? '')==='subscribe' && is_string($_GET['hub_verify_token'] ?? null)
            && is_string($_GET['hub_challenge'] ?? null) && $webhook->verify($_GET['hub_verify_token'])) {
            echo $_GET['hub_challenge'];
        } else { http_response_code(403); echo 'Verification failed'; }
    } elseif ($method==='POST') {
        if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0)>2097152) { http_response_code(413); exit; }
        $body=file_get_contents('php://input',false,null,0,2097153);
        if ($body===false || strlen($body)>2097152) { http_response_code(413); exit; }
        $webhook->receive($body,$_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '');
        echo 'EVENT_RECEIVED';
    } else { http_response_code(405); header('Allow: GET, POST'); }
} catch (\UnexpectedValueException $e) {
    http_response_code(403); echo 'Invalid signature';
} catch (\JsonException | \InvalidArgumentException $e) {
    http_response_code(400); echo 'Invalid payload';
} catch (\Throwable $e) {
    error_log('WhatsApp webhook: storage/configuration failure (' . get_class($e) . ')');
    http_response_code(503); echo 'Temporarily unavailable';
}
