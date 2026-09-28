-- Apply before restarting the worker. No changes to native VICIdial tables.
-- Single durable checkpoint prevents rereading old messages after each restart.
CREATE TABLE IF NOT EXISTS wa_worker_state (
  name VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
  last_id BIGINT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4;
