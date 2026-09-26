-- Uses MyISAM for VICIdial installations with InnoDB disabled.
-- Bridge state only. No changes to VICIdial tables and no credentials in this migration.
CREATE TABLE IF NOT EXISTS wa_sessions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  did_id INT UNSIGNED NOT NULL,
  phone_number_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  sender VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  group_id VARCHAR(100) NOT NULL,
  member VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  member_name VARCHAR(100) NOT NULL,
  lead_id BIGINT UNSIGNED NULL,
  chat_id BIGINT UNSIGNED NULL,
  state VARCHAR(20) NOT NULL DEFAULT 'provisioning',
  last_inbound BIGINT UNSIGNED NOT NULL,
  created_at BIGINT UNSIGNED NOT NULL,
  closed_at BIGINT UNSIGNED NULL,
  close_reason VARCHAR(40) NULL,
  UNIQUE KEY member (member),
  KEY customer (phone_number_id, sender, state),
  KEY chat (chat_id),
  KEY expiry (state, last_inbound)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS wa_inbox (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  event_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  phone_number_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  kind VARCHAR(10) NOT NULL,
  payload MEDIUMTEXT NOT NULL,
  received_at BIGINT UNSIGNED NOT NULL,
  state VARCHAR(20) NOT NULL DEFAULT 'pending',
  session_id BIGINT UNSIGNED NULL,
  attempts INT UNSIGNED NOT NULL DEFAULT 0,
  next_attempt BIGINT UNSIGNED NOT NULL DEFAULT 0,
  error_code VARCHAR(100) NULL,
  UNIQUE KEY event_key (event_key),
  KEY pending (state, next_attempt, id)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS wa_outbox (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  session_id BIGINT UNSIGNED NOT NULL,
  source_id BIGINT UNSIGNED NOT NULL,
  part INT UNSIGNED NOT NULL,
  body TEXT NOT NULL,
  state VARCHAR(20) NOT NULL DEFAULT 'pending',
  attempts INT UNSIGNED NOT NULL DEFAULT 0,
  next_attempt BIGINT UNSIGNED NOT NULL DEFAULT 0,
  wamid VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NULL,
  delivery_status VARCHAR(20) NULL,
  error_code VARCHAR(100) NULL,
  created_at BIGINT UNSIGNED NOT NULL,
  sent_at BIGINT UNSIGNED NULL,
  UNIQUE KEY source_part (session_id, source_id, part),
  KEY pending (state, next_attempt, id),
  KEY message (wamid)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4;

-- Write-ahead journal for recoverable inserts into legacy MyISAM VICIdial tables.
CREATE TABLE IF NOT EXISTS wa_vici_operations (
  operation_key VARCHAR(150) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
  target_table VARCHAR(64) NOT NULL,
  target_id BIGINT UNSIGNED NOT NULL,
  row_json MEDIUMTEXT NOT NULL,
  state VARCHAR(10) NOT NULL DEFAULT 'planned'
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4;
