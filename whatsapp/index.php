<?php
// No database access or secrets. Derive the installation URL from the web request.
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'none'; base-uri 'none'; frame-ancestors 'none'");
$host=$_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
if (!preg_match('/^[a-zA-Z0-9.\-\[\]:]+$/D',$host)) { http_response_code(400); exit; }
$path=rtrim(str_replace('\\','/',dirname($_SERVER['SCRIPT_NAME'] ?? '/whatsapp/index.php')),'/');
$url='https://'.$host.$path.'/webhook.php';
echo '<!doctype html><html lang="en"><meta charset="utf-8"><title>WhatsApp chat webhook</title><h1>WhatsApp chat webhook</h1><p>Callback URL:</p><pre>'
    .htmlspecialchars($url,ENT_QUOTES,'UTF-8').'</pre><p>Use the verification token configured on the chat inbound group. Subscribe to the WhatsApp messages field. The callback must be reachable over HTTPS.</p></html>';
