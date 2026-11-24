# Phase 4 — WhatsApp Business Platform (Cloud API) shared inbox

> **Status: verified against MOCKED Meta responses only.** Nothing here has been run against a real WhatsApp
> Business Account. The real-account checklist in §8 must be completed by a human before any tenant relies on it.

## 1. What was built

- **One WhatsApp number per workspace.** An Admin connects it at *WhatsApp setup* (`/integrations/whatsapp`) with the
  WhatsApp Business Account (WABA) id, the phone number id and a System User access token. Before anything is stored,
  Meta must confirm that the token can read the number and that the number belongs to that WABA. A number already
  connected to another workspace is refused.
- **Webhook** `GET|POST /webhooks/whatsapp`: verify-token handshake, `X-Hub-Signature-256` HMAC over the raw body with
  `META_APP_SECRET`, size and item caps, rate limit (1200/min per IP). Inbound messages and delivery statuses are
  written to the database **before** the 200 is returned, so a database failure makes Meta redeliver.
- **Shared inbox** (`/inbox`): conversation list with search and filters (unread, unlinked, ambiguous), message pane,
  reply box, template picker, link-to-lead. The page polls every `WHATSAPP_INBOX_POLL_SECONDS` (default 10).
- **Lead linking.** When a new contact writes:
  - exactly one lead in the workspace has that phone number → the conversation is linked to it;
  - two or more leads match → the conversation is marked *ambiguous* and a person picks the lead;
  - none match and *Auto-create leads* is on → a lead is created (source `whatsapp`, owner = the account's default
    owner, else unassigned).
  The lead page shows a "WhatsApp chat" link.
- **Sending.** Free-form text is allowed only inside the 24-hour customer-service window (since the contact last
  wrote). Outside it, only an APPROVED template can be sent. Templates are synced from Meta on connect and with
  *Sync templates*. Sends are queued (`DeliverWhatsAppMessage`), and the rules are re-checked when the job runs.
- **Opt-out.** An inbound message that is exactly `STOP`, `UNSUBSCRIBE` or `STOP ALL` (any case) marks the contact
  opted out; `START` or `SUBSCRIBE` opts them back in. Opted-out contacts cannot be messaged.
- **Delivery status** only moves forward: `queued → sent → delivered → read`; `failed` is terminal. Redelivered or
  out-of-order statuses are harmless.

### 1.1 Data model (migration `2026_11_23_000100_create_whatsapp_tables`)

`whatsapp_accounts`, `whatsapp_contacts`, `whatsapp_conversations`, `whatsapp_messages`, `whatsapp_templates`. Every
cross-table reference is a tenant-composite foreign key `(tenant_id, x)`, so the database rejects rows that point at
another workspace. `phone_number_id` and `wamid` are globally unique. Access tokens and message bodies are encrypted
at rest (Laravel encrypted casts); the send job payload is encrypted on the queue.

### 1.2 Permissions

| Action | Admin | Sales manager | Sales agent |
|---|---|---|---|
| Connect, update, disconnect, sync templates, validate | ✓ | | |
| See conversations | all | all | only those linked to leads they own |
| Reply, send templates | ✓ | ✓ | on conversations they can see |
| Link a conversation to a lead | ✓ | ✓ | |

A conversation the user may not see is a 404, not a 403.

## 2. Prerequisites (per deployment and per workspace)

1. The platform Meta app (the same one used for Lead Ads, see `docs/PHASE_3_META_LEAD_ADS.md`) with the **WhatsApp**
   product added.
2. In the app's *WhatsApp → Configuration*: callback URL `https://<APP_URL>/webhooks/whatsapp`, verify token =
   `WHATSAPP_WEBHOOK_VERIFY_TOKEN`, and subscribe to the **messages** field.
3. Per workspace: a WABA with a registered phone number, and a **System User** token from that business with
   `whatsapp_business_messaging` and `whatsapp_business_management`, assigned to the WABA. Business verification and
   display-name approval are Meta's process and are outside this app.

## 3. Environment variables

| Variable | Required | Notes |
|---|---|---|
| `WHATSAPP_WEBHOOK_VERIFY_TOKEN` | to enable | ≥ 24 random characters. Blank keeps the webhook closed (handshake answers 403). |
| `META_APP_SECRET` | yes | Webhook signatures and `appsecret_proof` use the platform app secret. |
| `META_GRAPH_VERSION` | yes | Shared with Lead Ads; no default in code. |
| `WHATSAPP_JOB_TRIES` | no | Default 5; backoff 10 s, 1 min, 5 min, 15 min. |
| `WHATSAPP_INBOX_POLL_SECONDS` | no | Default 10. |
| `WHATSAPP_WEBHOOK_MAX_BYTES` / `_MAX_ITEMS` | no | Defaults 256 KB / 200 items. |

`crm:preflight` (and a production HTTP boot) fails if the verify token is set but shorter than 24 characters, or if
it is set while `META_APP_SECRET` or `META_GRAPH_VERSION` is missing. Sends need a real queue worker
(`php artisan queue:work`), as for Meta imports.

## 4. Errors and retries

| Meta error | Treated as | Effect |
|---|---|---|
| 130429, 131048, 131056, 80007 | rate limit | job retries with backoff (honours `Retry-After`) |
| 131000, 131016, 133004, HTTP 5xx, timeouts | transient | job retries |
| 190 / auth errors | reauthorization | account marked *reauthorization required*; message failed with a readable reason |
| other 131xxx–135xxx | invalid request | message failed, error code and title shown in the inbox |

*Validate now* on the setup page re-checks the token on demand. Audit-log events: `whatsapp.account.connected`,
`reconnected`, `settings_updated`, `reauthorization_required`, `disconnected`, `whatsapp.conversation.linked`,
`whatsapp.lead.created`, `whatsapp.webhook.rejected` (at most one per IP per minute).

## 5. Known limitations

- **Possible duplicate send.** The Cloud API has no idempotency key. If a worker dies after Meta accepted a message
  but before its `wamid` is saved, the retry sends it again. Rare, but not prevented.
- **Statuses for unknown `wamid`s are ignored** (e.g. messages sent from the WhatsApp Business app or another tool on
  the same number). Such messages do not appear in the inbox.
- **No media.** For images, documents, audio, video, stickers, locations and contacts only the type (and any
  caption) is stored; the inbox shows "[image message]" etc. Files are not downloaded and cannot be sent.
- **No scheduled token check.** Expiry is detected when a send or *Validate now* fails, not proactively.
- **No sweeper for stuck sends.** A message whose job was lost stays *queued*.
- **One number per workspace**, and polling rather than push for the inbox.
- **No template management**: templates are created and approved in WhatsApp Manager; only body parameters
  (`{{1}}`…) are supported, not header/button parameters.
- Message retention and deletion follow the lead/workspace; there is no separate WhatsApp retention job.

## 6. Privacy notes (engineering, not legal advice)

Message bodies are personal data and are encrypted at rest; they are hidden from model serialization and never
logged (`WhatsAppLog` strips bodies, tokens and phone numbers). Opt-outs are honoured at send time. Workspaces are
responsible for having consent to message contacts and for using templates under Meta's commerce and messaging
policies.

## 7. Testing

- PHPUnit `tests/Feature/WhatsApp/` (webhook, send, inbox, integration) — 47 tests.
- Vitest `resources/js/__tests__/whatsapp-inbox.test.tsx` — 13 tests.
- Playwright `e2e/whatsapp.cjs` (10 checks) against `e2e/mock-graph-server.cjs`: `bash e2e/run.sh whatsapp`. CI runs
  it with the other browser suites.

## 8. Real-account verification checklist (human, with authorized assets)

1. Configure the webhook in the Meta app; confirm the handshake succeeds and a wrong token is rejected.
2. Connect a test number; confirm templates sync and the app subscription shows *subscribed*.
3. Send a message from a personal phone; confirm it appears, the lead is created/linked, and a reply arrives.
4. Wait past 24 hours (or use a contact that never wrote); confirm free text is blocked and an approved template sends.
5. Confirm `sent → delivered → read` updates, and that a failed send (e.g. invalid number) shows Meta's reason.
6. Send `STOP`, confirm sending is blocked; send `START`, confirm it resumes.
7. Revoke the System User token; confirm the account flips to *reauthorization required*.
8. Re-check the error codes in §4 and the request shapes in `CloudApiClient` against Meta's current docs for the
   chosen `META_GRAPH_VERSION`.

## 9. Go / no-go

Go only when §8 passes on a staging host, the queue worker is running, `crm:preflight` is clean, and the limitations
in §5 are acceptable for the first tenants.
