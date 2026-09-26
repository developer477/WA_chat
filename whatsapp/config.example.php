<?php
// Copy to config.local.php. This file contains deployment paths, never Meta credentials.
return [
    'chat_directory' => dirname(__DIR__) . '/chat_customer',
    'graph_version' => 'v25.0',
    'poll_seconds' => 1,
    // Must be accepted by vicidial_chat_archive.status. check.php validates it.
    'archive_complete_status' => 'DEAD',
    'batch_size' => 100,
    // null discovers the web root by locating sounds_web_directory above chat_directory.
    'media_web_root' => null,
    'media_max_bytes' => 104857600,
];
