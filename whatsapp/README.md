# WhatsApp customer bridge

PHP 7.4+ with mysqli/mysqlnd, cURL, mbstring and JSON. The worker and webhook must use the **same VICIdial primary database**. Existing customer and agent code is unchanged. This is a customer transport bridge, not a template campaign sender or replacement agent UI.

## Configuration in VICIdial

Reserve these custom fields on participating DIDs and chat groups:

| Table | Field | Meaning |
|---|---|---|
| `vicidial_inbound_dids` | `did_pattern` | Business WhatsApp number, e.g. `917045963025` |
| | `custom_one` | Chat group ID, e.g. `TSIM` |
| | `custom_two` | Meta phone-number ID, e.g. `773505685855835` |
| | `did_active` | Must be `Y` to process customer messages/send replies |
| `vicidial_inbound_groups` | `custom_one` | Bearer token |
| | `custom_two` | Meta App Secret (for POST signature validation) |
| | `custom_three` | Webhook verification token: choose a random value |
| | `active`, `group_handling` | `Y`, `CHAT` |
| | `hold_time_option_callback_list_id` | Valid list for new chat leads |

The DID's voice `group_id` and other voice routing fields are not changed or used by this integration. The chat group is always resolved from the DID's `custom_one`. Multiple DIDs can share a group and its credentials; each DID retains its own phone-number ID. The token must authorize all numbers using that group. Duplicate phone-number IDs are rejected.

Your current bearer token is 202 characters; the reported group custom-field form limit is 2,000. Paste credentials using the existing VICIdial administration interface; do not put them in source, migrations, shell commands or logs. Anyone allowed to view these custom fields can see the credentials; apply the existing group's administration permissions accordingly. A token rotation takes effect on subsequent worker ticks. A single Meta app callback should use the same verification token on its participating groups.

The example `TSIM`/number values above are documentation only. No business number, chat group or hostname is hard-coded in the bridge. All configured WhatsApp DIDs are discovered through the database join. Disabled accounts' signed callbacks are retained, but message processing/sending pauses. Webhook verification/status reconciliation still works for those accounts.

## Install

For testing, connect from the development laptop:

```sh
ssh -C -i ~/ssh_key_dialers staff@deven024.cc.warmconnect.in
```

On this test server, VICIdial files are in `/srv/www/htdocs` and the MySQL database is `asterisk`. See [test server access](../README.md#test-server-access). Confirm the remote repository checkout path before running Git commands.

1. Deploy `whatsapp/` alongside the existing `chat_customer/` directory. Copy `config.example.php` to `config.local.php` only if deployment paths/defaults need adjustment. The bridge **reuses `chat_customer/dbconnect_mysqli.php`** and `/etc/astguiclient.conf`; it refuses the sample fallback DB credentials if that config file is absent. It never connects to central PostgreSQL.
2. Apply `sql/001_bridge.sql` and `sql/002_reply_cursor.sql` to the VICIdial database with your normal DB administration process. They create five MyISAM bridge tables: sessions, inbox, outbox, a native-insert recovery journal, and a single worker checkpoint. No VICIdial table/column changes or credential values are included.
3. Configure the DID and chat-group fields above. Confirm existing custom fields are not used for another purpose. The target server must have the TEXT group fields described in #8186; this package does not widen them.
4. Run `php check.php`. It checks enabled mappings, required columns, message primary keys, bridge storage engines, chat enablement and archive status support. It prints **token length only**, never credentials. Resolve any schema differences before starting the worker. The database account needs SELECT/INSERT/UPDATE/DELETE and LOCK TABLES on the relevant tables plus GET_LOCK; only the installation account needs CREATE.
5. Unclaimed chats use the existing customer-side `DROP` / lead `CDROP` behavior. For an agent-handled chat, customer departure removes only that customer's participant and adds the private “has left chat” notice. It preserves the live chat, its messages, and agent participants. No agent disposition is performed. The former `archive_complete_status` setting is no longer used.
6. Run `php worker.php --once` for one processing cycle, or install the supplied systemd service after adjusting its PHP path, deployment path and OS user. The worker must be supervised and restart on failure. One advisory lock per database prevents two workers from sending duplicate replies. Keep all existing VICIdial chat queue/maintenance services running.
7. Limit web exposure to `index.php` and `webhook.php`. Apache `.htaccess` rules are included but require `AllowOverride` support; configure equivalent restrictions in the virtual host if disabled. On nginx, allow only those two PHP entrypoints and deny the rest of `/whatsapp/`. Do not publish SQL, test fixtures or deployment files. Worker/check/status scripts also reject non-CLI execution.

If an earlier attempt reported `Unknown storage engine 'InnoDB'`, update the PHP files **and** migration, then rerun `sql/001_bridge.sql`. It creates any missing tables without deleting existing rows; no storage-engine configuration change or table drop is needed. The preflight check now accepts MyISAM or InnoDB bridge tables.

The initial migration is repeatable (`CREATE TABLE IF NOT EXISTS`); it is not an upgrade mechanism for a different bridge schema. Back up and compare any existing `wa_*` tables before applying it to an installation that already uses those names.

## Upgrade to message-triggered expiry

Stop the worker, apply `sql/002_reply_cursor.sql`, update the PHP files, run `check.php` and restart the worker. The new `wa_worker_state` table contains one durable native-message cursor. Its initial value is computed from existing WhatsApp chats; existing outbox entries prevent duplicate sends during the initial catch-up. Do not delete/reset this checkpoint during ordinary operation. No existing VICIdial schema changes are needed.

The metadata line is added to new chats as a private native log entry: `WhatsApp | Receiving number: +...`. It is not sent to the customer and is not added retrospectively to old sessions. This uses the standard log fields; the test server does not have the custom website `country` column.

## Webhook setup

Visit `https://YOUR-SERVER/whatsapp/`. The page derives and displays the full callback URL from the web server request variables; no configured hostname is needed. With the standard directory layout the URL is:

```
https://YOUR-SERVER/whatsapp/webhook.php
```

In the Meta app managing this WhatsApp account, configure that callback URL and the verification token from the group's `custom_three`. Subscribe the WhatsApp Business Account to the app and its `messages` webhook field. The phone-number ID is not the WABA ID; do not substitute it in account subscription operations. Check existing subscriptions before replacing an app-level callback used by another integration.

GET verification echoes the challenge only on an exact token match. POST uses HMAC-SHA256 of the **raw request body** and `X-Hub-Signature-256`, with the Meta App Secret in group `custom_two`. The bearer token is used only for outbound Graph API requests. HTTPS with a publicly trusted certificate and Internet reachability are required. Do not enable authentication that blocks Meta at the callback route. Rate/body limits at the web server should allow normal Meta batches (application limit: 2 MiB).

Test first with a real message to `917045963025`, accept its queued chat in `TSIM`, and send an agent reply. Dashboard-generated test events alone do not verify that the real account subscription is connected. Sending/registration setup is outside the code migration: no live webhook or Meta account changes are made by installing the files.

## Incoming media

Images (JPEG/PNG), stickers (WebP), audio, video, PDF, plain text and supported Office documents are downloaded from Meta after the agent accepts the chat. The worker uses the receiving number's bearer token and verifies the downloaded SHA-256 against the webhook. Files are streamed with a 100 MiB ceiling (configurable with `media_max_bytes`), HTTPS-only Meta download URLs and no redirects. Customer filenames are display labels only; stored names are deterministic hashes with an allowed extension.

Files live in `system_settings.sounds_web_directory/wa_media` under the web root. The bridge discovers that root by looking above the configured customer-chat directory for the existing sounds directory. This supports the test server's `/srv/www/htdocs` and `/var/www/htdocs` symlinked paths and nested checkout without hardcoding either path. For a different layout, set `media_web_root` in `config.local.php`. The worker user needs permission to create/write `wa_media`; no existing upload script or system setting is modified.

The chat log receives a relative HTML attachment link, so no hostname is needed. Apache `.htaccess` rules in the generated media directory disable listing, deny dotfiles, and set download/nosniff headers when `mod_headers` is enabled. Apply equivalent rules if `.htaccess` is disabled or another web server is used. Links use the same unguessable-file-URL access model as existing web chat attachments; anyone with a link can download the file. Files remain available for archived chat links; there is no automatic deletion policy.

Transient downloads retry up to eight times, reusing a verified saved file after interruptions. Permanent/exhausted failures insert an explanatory placeholder, retain the caption, and set the inbox to `failed` with a `media_*` error visible in `status.php`. Existing already-processed placeholder messages are not automatically replayed; send a new image to test after deployment. Agent-to-customer attachment URLs still go as text links; native outbound media upload is outside this change.

Run `check.php` as the worker user to check media directory permissions. Media storage itself needs no schema changes; follow the upgrade instructions above for the reply cursor.

## Message/session behavior

- Webhook requests are authenticated and saved to the inbox before returning 200. Duplicate message IDs cannot create duplicate events. A DB failure returns 503 for retry; invalid signatures return 403. Batch messages and status events are handled separately. Unmapped numbers in a correctly signed account batch are ignored.
- The worker follows the supplied customer-side SQL contract for lead creation, WAITING chats, participants, LIVE message insertion and keepalive. It uses the sender's full international number for lead lookup; it does not strip country codes or match national suffixes. Existing leads are reused without overwriting their name/email. Separate business numbers get separate chat sessions even for the same customer.
- Waiting messages are held in the durable inbox until the chat becomes LIVE, including the initial customer message. Supported incoming media is downloaded and represented as an attachment link with its caption (see above). Other message types retain a placeholder. Text/interactive/button replies are supported. This release expects numeric WhatsApp sender IDs; nonnumeric sender-identity formats require a future mapping extension and are rejected explicitly.
- Customer HTML is escaped, including the legacy pipe delimiter. Emoji are represented as numeric HTML entities in native chat rows to support older three-byte utf8 tables without loss. The bridge's own tables use utf8mb4.
- A single bulk presence heartbeat runs at most once every five seconds for active mapped customers whose native chats still exist. This is independent of the messaging window and performs no expiry checks. Only level-zero messages whose poster exists in `vicidial_users` are sent out; customer echoes, private notes and COUNTRY AND IP ADDRESS metadata are excluded. Transfers do not change the originating WhatsApp number. Agent HTML is converted to plain text, preserving HTTP(S) links. Long replies split into ordered 4,000-character parts. No new translation logic is introduced; the message stored for the customer is used.
- Agent replies are read in primary-key order from the live and archived logs using one shared durable cursor, in bounded batches (`reply_batch_size`, default 1000). Brief shared table locks give a consistent view across archival moves. There are no per-session history scans on each tick. Each source message/part has a unique outbox key; the cursor advances only after every part is queued. Native message IDs must retain their normal monotonically increasing sequence and be preserved on archival. Editing a row already passed by the cursor does not create a new message; translation code must store the customer-facing text before it is eligible for delivery.
- The 86,400-second window is checked only when processing a customer message or an outbound reply. It is measured from the last customer-message timestamp, never from an agent reply or status receipt. A silent chat remains untouched; there is no timer-driven expiry scan. A new customer message less than 24 hours after the previous one continues an open chat. At or beyond the boundary it first performs customer departure on the old chat and then creates a new `WAITING` chat.
- An actual outbound reply outside the window is not sent and triggers the same customer departure. A pending newer customer event defers the outbound decision until that event is processed. Unclaimed chats are archived as `DROP`; agent-handled chats keep their live row and agent participants, with only the customer removed and one private leave notice added. Closing a bridge session never disposes an agent-handled chat.
- Agent closure is discovered while processing message work. A later customer message starts a new chat if the old native chat is gone. The shared log cursor can still collect a final archived agent reply and send it within the old session's remaining window. Buffered incoming messages are revisited when the native chat becomes `LIVE` or disappears, rather than repeatedly processing every waiting session.
- No template is sent to reopen an expired window. No agent/admin code is added.

## Durability and recovery

VICIdial tables can be MyISAM, so a SQL transaction cannot make a native insert and a separate journal update atomic. The migration defaults to MyISAM, so installations with InnoDB disabled are supported. Existing InnoDB bridge tables are also supported and do not need conversion. The bridge records the planned native ID/row first, briefly locks the target table while reserving/inserting, and replays unfinished operations by checking that ID. Private information/leave notices use the same journal. A departure interrupted after the notice is recorded resumes on worker startup without duplicating the notice; startup recovery only finishes departures already started by messages. It checks archives too, and handles a reserved ID taken by another writer. Never delete `wa_vici_operations` independently of related sessions/events. Native table locks are short and never span HTTP requests.

Webhook batches do not require rollback: each event is individually idempotent. If a later insert fails, the request returns 503 and Meta can retry the entire batch; the event-key uniqueness constraint preserves already saved events. New sessions derive a stable participant key from their first inbox event, so an interruption between session creation and inbox linking can recover the same session, including after closure. This is process/retry recovery, not a guarantee against disk corruption or machine power loss; MyISAM retains its normal nontransactional durability characteristics.

A worker dying while sending an HTTP request leaves the outbox in `sending`. On startup it becomes `uncertain`, **not automatically retried**: the original send might have succeeded. The opaque callback correlation value `wa:<outbox ID>` allows later signed delivery events to resolve it. Pending later replies for that session wait behind an uncertain reply, preserving order. Definite transient Graph API rejections retry with bounded exponential backoff; definite permanent rejections and exhausted retries are recorded as failed. Delivery states never regress from read/delivered on late callbacks.

Run `php status.php` for counts and recent failed/uncertain record IDs. Investigate token/permission errors, account configuration or delivery failures using these IDs. For an uncertain send, verify its Meta delivery outcome before manually setting it accepted or allowing a retry. If no evidence resolves it, an explicit operator choice is required: retry risks duplication, whereas cancelling risks losing that reply. There is no exactly-once HTTP delivery guarantee. No customer messages or tokens are printed by the status command.

Retained inbox events include customer content; apply your existing chat retention/access policy. Do not purge unprocessed/uncertain work or closed sessions with pending outbound work. There is no automatic purge in this first release.

## Validation

```
php tests/unit.php
WA_TEST_SOCKET=/path/to/isolated/mysql.sock php tests/integration.php
WA_TEST_SOCKET=/path/to/isolated/mysql.sock php tests/integration.php --innodb
```

Integration tests create a random `wa_chat_test_*` database, load a minimal VICIdial contract fixture with MyISAM native and bridge tables (or all InnoDB with `--innodb`), and remove only that database afterward. **Use a disposable local server, never production.** `WA_TEST_USER`/`WA_TEST_PASSWORD` can configure test credentials. The fixture is not a substitute for the deployed schema. Graph sending is mocked: the tests never message customers.

Before release, perform a staging agent session covering initial WAITING messages, text/links/emoji, translations, transfer, blocked customers, agent closure, live and waiting expiry, service restart, two numbers sharing a group, actual Meta delivery callbacks and failure visibility. Confirm native customer departure and normal VICIdial maintenance together. A successful unit/integration run does not mean the system is deployed or that Meta routing is configured.

Validation covers message-triggered expiry, preservation of agent chats, private information/leave notices, idle query counts independent of session count, durable reply polling across archive moves and restarts, injected partial-write failures, and incoming media. Graph sends in the automated tests are mocked. The test server has also been used for real two-way text and incoming-image validation.
