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

1. Deploy `whatsapp/` alongside the existing `chat_customer/` directory. Copy `config.example.php` to `config.local.php` only if deployment paths/defaults need adjustment. The bridge **reuses `chat_customer/dbconnect_mysqli.php`** and `/etc/astguiclient.conf`; it refuses the sample fallback DB credentials if that config file is absent. It never connects to central PostgreSQL.
2. Apply `sql/001_bridge.sql` to the VICIdial database with your normal DB administration process. It creates only four MyISAM bridge tables: sessions, inbox, outbox and a native-insert recovery journal. No VICIdial table/column changes or credential values are included.
3. Configure the DID and chat-group fields above. Confirm existing custom fields are not used for another purpose. The target server must have the TEXT group fields described in #8186; this package does not widen them.
4. Run `php check.php`. It checks enabled mappings, required columns, message primary keys, bridge storage engines, chat enablement and archive status support. It prints **token length only**, never credentials. Resolve any schema differences before starting the worker. The database account needs SELECT/INSERT/UPDATE/DELETE and LOCK TABLES on the relevant tables plus GET_LOCK; only the installation account needs CREATE.
5. `archive_complete_status` defaults to `DEAD`. **Confirm this is the terminal archive status accepted by your deployed VICIdial schema and completion workflow.** `check.php` rejects it if excluded by an ENUM. If your installation uses a different terminal status, set its actual value in `config.local.php`; do not add an enum value merely to make the check pass. Waiting unclaimed chats use the existing customer-side `DROP` / lead `CDROP` behavior. The live expiry path archives/removes the chat and participants; it does not invent an agent disposition or alter agent login state. Verify this transition with a real agent in staging before enabling general traffic.
6. Run `php worker.php --once` for one processing cycle, or install the supplied systemd service after adjusting its PHP path, deployment path and OS user. The worker must be supervised and restart on failure. One advisory lock per database prevents two workers from sending duplicate replies. Keep all existing VICIdial chat queue/maintenance services running.
7. Limit web exposure to `index.php` and `webhook.php`. Apache `.htaccess` rules are included but require `AllowOverride` support; configure equivalent restrictions in the virtual host if disabled. On nginx, allow only those two PHP entrypoints and deny the rest of `/whatsapp/`. Do not publish SQL, test fixtures or deployment files. Worker/check/status scripts also reject non-CLI execution.

If an earlier attempt reported `Unknown storage engine 'InnoDB'`, update the PHP files **and** migration, then rerun `sql/001_bridge.sql`. It creates any missing tables without deleting existing rows; no storage-engine configuration change or table drop is needed. The preflight check now accepts MyISAM or InnoDB bridge tables.

The initial migration is repeatable (`CREATE TABLE IF NOT EXISTS`); it is not an upgrade mechanism for a different bridge schema. Back up and compare any existing `wa_*` tables before applying it to an installation that already uses those names.

## Webhook setup

Visit `https://YOUR-SERVER/whatsapp/`. The page derives and displays the full callback URL from the web server request variables; no configured hostname is needed. With the standard directory layout the URL is:

```
https://YOUR-SERVER/whatsapp/webhook.php
```

In the Meta app managing this WhatsApp account, configure that callback URL and the verification token from the group's `custom_three`. Subscribe the WhatsApp Business Account to the app and its `messages` webhook field. The phone-number ID is not the WABA ID; do not substitute it in account subscription operations. Check existing subscriptions before replacing an app-level callback used by another integration.

GET verification echoes the challenge only on an exact token match. POST uses HMAC-SHA256 of the **raw request body** and `X-Hub-Signature-256`, with the Meta App Secret in group `custom_two`. The bearer token is used only for outbound Graph API requests. HTTPS with a publicly trusted certificate and Internet reachability are required. Do not enable authentication that blocks Meta at the callback route. Rate/body limits at the web server should allow normal Meta batches (application limit: 2 MiB).

Test first with a real message to `917045963025`, accept its queued chat in `TSIM`, and send an agent reply. Dashboard-generated test events alone do not verify that the real account subscription is connected. Sending/registration setup is outside the code migration: no live webhook or Meta account changes are made by installing the files.

## Message/session behavior

- Webhook requests are authenticated and saved to the inbox before returning 200. Duplicate message IDs cannot create duplicate events. A DB failure returns 503 for retry; invalid signatures return 403. Batch messages and status events are handled separately. Unmapped numbers in a correctly signed account batch are ignored.
- The worker follows the supplied customer-side SQL contract for lead creation, WAITING chats, participants, LIVE message insertion and keepalive. It uses the sender's full international number for lead lookup; it does not strip country codes or match national suffixes. Existing leads are reused without overwriting their name/email. Separate business numbers get separate chat sessions even for the same customer.
- Waiting messages are held in the durable inbox until the chat becomes LIVE, including the initial customer message. Unsupported media types are retained in the event JSON and shown as a placeholder with any caption. Text/interactive/button replies are supported. This release expects numeric WhatsApp sender IDs; nonnumeric sender-identity formats require a future mapping extension and are rejected explicitly.
- Customer HTML is escaped, including the legacy pipe delimiter. Emoji are represented as numeric HTML entities in native chat rows to support older three-byte utf8 tables without loss. The bridge's own tables use utf8mb4.
- Every active mapped customer receives keepalive updates. Only level-zero messages whose poster exists in `vicidial_users` are sent out; customer echoes, private notes and COUNTRY AND IP ADDRESS metadata are excluded. Transfers do not change the originating WhatsApp number. Agent HTML is converted to plain text, preserving HTTP(S) links. Long replies split into ordered 4,000-character parts. No new translation logic is introduced; the message stored for the customer is used.
- Both live and archived logs are checked so a final reply can be picked up after agent closure. Each source message/part has a unique outbox key. A changed message after it was already sent does **not** produce a second WhatsApp message. Confirm your translation code stores the final customer-facing text before it is eligible for delivery, just as the customer polling interface expects.
- Expiry is exactly 86,400 seconds after the latest customer timestamp. Older or duplicate callbacks never extend it. Business messages/status receipts do not extend it. The worker rechecks before each send, stops free-form replies at expiry and archives/closes the chat. Chat closure by an agent also ends the mapping; a later customer message opens a new session. A final already-written agent reply may be delivered after agent closure, but only within the remaining 24-hour window.
- No template is sent to reopen an expired window. No agent/admin code is added.

## Durability and recovery

VICIdial tables can be MyISAM, so a SQL transaction cannot make a native insert and a separate journal update atomic. The migration defaults to MyISAM, so installations with InnoDB disabled are supported. Existing InnoDB bridge tables are also supported and do not need conversion. The bridge records the planned native ID/row first, briefly locks the target table while reserving/inserting, and replays unfinished operations by checking that ID. It checks archives too, and handles a reserved ID taken by another writer. Never delete `wa_vici_operations` independently of related sessions/events. Native table locks are short and never span HTTP requests.

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

Before release, perform a staging agent session covering initial WAITING messages, text/links/emoji, translations, transfer, blocked customers, agent closure, live and waiting expiry, service restart, two numbers sharing a group, actual Meta delivery callbacks and failure visibility. Confirm native expiry/archive status and normal VICIdial maintenance together. A successful unit/integration run does not mean the system is deployed or that Meta routing is configured.

Local validation: PHP 8.5 lint and 28 unit checks; integration coverage includes both MyISAM and InnoDB tables plus injected partial-batch and session-mapping failures. The MyISAM suite is also run with InnoDB disabled on MariaDB 10.11.19. Existing customer directories were verified unchanged against the baseline commit. No production token was fetched and no live WhatsApp messages were sent.
