-- Run with the worker stopped, after 001 and 002. Only bridge tables change.
-- MariaDB's IF NOT EXISTS makes an interrupted upgrade safe to repeat.
ALTER TABLE wa_inbox
  ADD COLUMN IF NOT EXISTS sender VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NULL,
  ADD COLUMN IF NOT EXISTS message_timestamp BIGINT UNSIGNED NULL,
  ADD INDEX IF NOT EXISTS customer_pending (phone_number_id,sender,state,message_timestamp),
  ADD INDEX IF NOT EXISTS session_pending (session_id,state,id);

-- Backfill once so expiry checks never need to parse retained JSON on each poll.
UPDATE wa_inbox SET
  sender=JSON_UNQUOTE(JSON_EXTRACT(payload,'$.from')),
  message_timestamp=CAST(JSON_UNQUOTE(JSON_EXTRACT(payload,'$.timestamp')) AS UNSIGNED)
WHERE kind='message' AND sender IS NULL AND JSON_VALID(payload);

ALTER TABLE wa_outbox
  ADD COLUMN IF NOT EXISTS notice_pending VARCHAR(20) NULL,
  ADD INDEX IF NOT EXISTS notice_pending (notice_pending),
  ADD INDEX IF NOT EXISTS session_pending (session_id,state,id);
