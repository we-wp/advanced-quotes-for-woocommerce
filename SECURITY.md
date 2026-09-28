# Security policy

Report a vulnerability privately through this repository's **Security → Report a vulnerability** action. Do not post customer quotes, credentials, database exports or exploit details in public issues. Use synthetic reproduction steps and the affected version.

## Design

- Quote management needs the `manage_wewp_quotes` capability, which activation gives to Administrator and Shop manager roles. Every admin action checks it and a WordPress nonce.
- Customers open a quote through a private link. The link key is an HMAC of a per-quote secret keyed with the site's WordPress auth salt, so a copy of the database alone does not reveal working links. Creating a new link revokes old ones. Sending to a different email address or customer also creates a new link. The quote page sends `noindex`, `no-referrer`, `no-store` and a restrictive Content-Security-Policy.
- Accept and decline require the link key or the owning customer account, plus a nonce. Acceptance claims the quote with a conditional database update, so parallel requests create one order.
- Sent revisions are immutable JSON snapshots with SHA-256 hashes. PDFs are rendered once and verified by hash on every download.
- The accepted order is rebuilt from the snapshot with its stored taxes. If WooCommerce reaches a different total, the order is deleted and the quote stays open.
- PDF rendering uses fixed, escaped templates, Dompdf's own DejaVu fonts and embedded PNG or JPEG logos from the media library. Remote resources, PHP execution and JavaScript execution are disabled in Dompdf.
- The request form uses a nonce, a honeypot field and a signed start time (3 seconds to 2 hours). Hourly limits apply per address (5), per recipient email (3) and per store (50, filter `wewp_aq_request_hourly_limit`). Quantities, items and message length are bounded.

A database administrator can change stored records and hashes. These controls are not cryptographic non-repudiation. Backups, host security, access control and lawful record retention remain the store operator's responsibility.

No response-time guarantee is offered. The plugin contains no telemetry and no update client.
