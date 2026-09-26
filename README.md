# WA_chat

VICIdial customer chat snapshots and a standalone PHP WhatsApp customer bridge for Redmine #8186.

- `chat_customer_ori/`: supplied unmodified VICIdial customer code.
- `chat_customer/`: supplied WarmConnect customization, preserved unchanged.
- `whatsapp/`: new integration. See [installation and operation](whatsapp/README.md).

The bridge uses VICIdial's database as the interface to agents. No agent/admin or existing customer source changes are required. Credentials are stored in existing DID/inbound-group custom fields, not source files.

Initial release supports customer/agent text, template button replies and text links to attachments. Native WhatsApp media transfer is not implemented; incoming media events are retained and represented in the chat with a visible placeholder/caption.
