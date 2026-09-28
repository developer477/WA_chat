# Production backport plan

Branch: `compat/legacy-vicidial`, based on `78aa38f`. This document records read-only findings from 2026-09-28 and the proposed work. The PHP/SQL backport has not been implemented or deployed.

## Access and verified environment

```sh
ssh -C -i ~/ssh_key_dialers staff@chat.cc.trikon.in
```

The key remains on the development laptop. `staff` has no sudo access. VICIdial is under `/srv/www/htdocs`, resolving to `/var/www/htdocs`; its database is `asterisk`. The existing customer directory is `/srv/www/htdocs/chat_customer`. Neither `/srv/www/htdocs/WA_chat` nor `/srv/www/htdocs/whatsapp` existed at inspection time.

| Component | Verified value |
|---|---|
| VICIdial version in `system_settings` | `2.14-853a` |
| VICIdial DB schema | `1657` |
| Database server | MariaDB `5.5.68` |
| PHP CLI | `/usr/bin/php`, version `5.4.16` |
| Installed PHP web package | `php-5.4.16-46.amzn2.0.2` |
| Apache | `2.4.52` |
| PHP cURL library | `7.79.1`, OpenSSL `1.0.2k-fips` |
| Relevant native table engines | MyISAM |
| Database server default character set | `latin1` |

The CLI has mysqli/mysqlnd, cURL, mbstring, JSON, OpenSSL and pcntl. Prepared-statement `get_result()` is available. An unauthenticated HTTPS HEAD request to `graph.facebook.com` completed with cURL error 0, certificate verification result 0 and HTTP 400. This confirms connectivity and certificate validation, not token permissions or message delivery. The web SAPI has not been probed by installing a PHP endpoint.

Database inspection used the existing readable `/etc/astguiclient.conf` without displaying its values. The configured DB account has SELECT/INSERT/UPDATE/DELETE/LOCK TABLES on `asterisk`; it lacks CREATE/ALTER. No customer records, bearer tokens or database passwords were exported.

## Compatibility findings

- PHP 5.4 cannot parse the current typed properties, scalar/return type declarations, null coalescing, arrow functions, argument unpacking, short destructuring, exponentiation or `finally` blocks. Runtime helpers including `hash_equals`, `array_column`, `array_key_last` and asynchronous pcntl signals are absent. Exception/JSON handling also needs an older-runtime implementation.
- MariaDB 5.5 has no JSON functions: the read-only `JSON_VALID` probe returned error 1305. The current maintenance migration uses JSON functions and newer conditional ALTER syntax. The worker already uses indexed scalar sender/timestamp columns for its hot queries; retain that approach.
- `vicidial_inbound_dids.custom_one` and `custom_two` exist as VARCHAR(100), so DID-to-chat-group and phone-number-ID routing can stay as designed.
- `vicidial_inbound_groups.custom_one`, `custom_two` and `custom_three` are absent. The present credential lookup cannot run until the storage decision below is implemented.
- The native chat, participant, log, lead and archive fields used by the bridge are present. Log `chat_id` is VARCHAR(20), `chat_level` is ENUM('0','1'), and the live log timestamp has an ON UPDATE clause. Preserve these definitions in the compatibility test fixture. Archive message IDs have a primary key but no auto increment; the bridge only requires auto increment on the live log.
- The existing customer departure code removes the customer participant, uses CDROP/DROP for unclaimed chats, records a private leave notice for agent-owned chats, and clears `chat_id_lead.status`. Keep that contract and the existing customer source files.

## Proposed implementation

1. Port only the bridge and its tests to PHP 5.4 syntax. Introduce small compatibility helpers for JSON errors, constant-time signature/token comparisons and missing array operations. Bind mysqli parameters by reference using the older API. Preserve lock release, temporary-file cleanup and crash recovery when replacing `finally`.
2. Supply MariaDB 5.5-compatible installation and upgrade steps. Detect columns/indexes before ALTER operations; perform any retained-message JSON backfill in bounded PHP batches. Keep explicit bridge encodings, MyISAM support, existing indexes and bounded maintenance. Do not add scans or extra per-session polling.
3. Proposed credential storage: add nullable TEXT `custom_one`, `custom_two` and `custom_three` columns to `vicidial_inbound_groups`, retaining the existing token/app-secret/verification-token mapping. This preserves the requested VICIdial storage design. Adding columns alone does not add input controls to the old frontend; initial credential setup needs a separate database administration step. Agree this schema/setup approach before implementing it. Do not widen DID fields or put credentials in the repository.
4. Test against PHP 5.4 and MariaDB 5.5 with a disposable schema reflecting production's column definitions. Retest signature verification, both message directions, media, migration reruns, ordering, five-second expiry, queued-message protection, failure notices and interrupted MyISAM operations. Check idle query counts.
5. Prepare concrete production installation and rollback instructions. An administrator must apply CREATE/ALTER operations and arrange the persistent worker service because neither the SSH account nor the configured DB account currently has the required installation privileges. Recheck the web PHP runtime and file permissions during installation. Do not change Meta routing or start message forwarding during compatibility inspection.

Production was only inspected. No checkout, bridge tables, credential fields, webhook or worker was installed, and no production customer chat was altered.
