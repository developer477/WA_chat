# WA_chat

VICIdial customer chat snapshots and a standalone PHP WhatsApp customer bridge for Redmine #8186.

- `chat_customer_ori/`: supplied unmodified VICIdial customer code.
- `chat_customer/`: supplied WarmConnect customization, preserved unchanged.
- `whatsapp/`: new integration. See [installation and operation](whatsapp/README.md).

The bridge uses VICIdial's database as the interface to agents. No agent/admin or existing customer source changes are required. Credentials are stored in existing DID/inbound-group custom fields, not source files.

Supports customer/agent text, template button replies and incoming WhatsApp images, documents, audio, video and stickers as downloadable chat attachments with captions. Agent attachments continue to be sent as text links; outbound native WhatsApp media is not implemented.

## Test server access

The project test server is `deven024.cc.warmconnect.in`. Connect from the development laptop as `staff`:

```sh
ssh -C -i ~/ssh_key_dialers staff@deven024.cc.warmconnect.in
```

The SSH private key stays on the laptop at `~/ssh_key_dialers`. This is the test server, not production.

- VICIdial files: `/srv/www/htdocs`
- MySQL database: `asterisk`

Confirm the remote repository checkout path before running Git commands.
