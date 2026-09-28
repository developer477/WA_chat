<?php
// Shared bootstrap; web entrypoints catch failures without disclosing credentials/SQL.
require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/Accounts.php';
require_once __DIR__ . '/src/Protocol.php';
require_once __DIR__ . '/src/Webhook.php';
require_once __DIR__ . '/src/Media.php';
require_once __DIR__ . '/src/GraphClient.php';
require_once __DIR__ . '/src/ReplyPoller.php';
require_once __DIR__ . '/src/Bridge.php';
require_once __DIR__ . '/src/Preflight.php';
require_once __DIR__ . '/src/Status.php';

function wa_bootstrap(): array
{
    $config=require __DIR__ . '/config.example.php';
    if (is_file(__DIR__ . '/config.local.php')) {
        $config=array_replace($config,require __DIR__ . '/config.local.php');
    }
    foreach (['mysqli','curl','mbstring','json'] as $extension) {
        if (!extension_loaded($extension)) { throw new RuntimeException('Required PHP extension missing: '.$extension); }
    }
    if (!is_file('/etc/astguiclient.conf')) {
        throw new RuntimeException('Missing /etc/astguiclient.conf; refusing legacy fallback credentials');
    }
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    // Reuse the existing, unmodified connection loader in its expected working directory.
    $original=getcwd();
    if (!chdir($config['chat_directory'])) { throw new RuntimeException('Chat directory unavailable'); }
    $use_slave_server=0;
    ob_start();
    try { require './dbconnect_mysqli.php'; }
    finally { ob_end_clean(); chdir($original); }
    ini_set('display_errors','0');
    if (!isset($link) || !($link instanceof mysqli)) { throw new RuntimeException('Database unavailable'); }
    return [new \WaChat\Database($link),$config];
}
