# Integration Requirements — Meta Lead Ads, Marketing API & WhatsApp Cloud API

Status: **Planning document.** Nothing here has been tested against the real Meta APIs yet. Meta changes permission names, review rules, Graph API versions and WhatsApp pricing/policy regularly — every item below must be re-checked against the current official documentation (developers.facebook.com) at configuration time.

Last updated: 2026-10-27

---

## 0. Multi-tenant ownership rules (see plan §3A.7)

- Every credential, Page, WhatsApp number, form and campaign belongs to **exactly one tenant** (`tenant_id`); an external asset ID (`page_id`, `phone_number_id`, `waba_id`, `leadgen_id`, `wamid`) is globally unique, so one tenant can never claim another tenant's asset.
- **Manual mode (initial testing):** a tenant Admin enters that tenant's own System User token (and optionally its own Meta app ID/secret). Webhook URL: `/webhooks/meta/{integration_public_id}`, which resolves the tenant and its per-integration app secret / verify token.
- **OAuth mode (future SaaS onboarding):** one platform Meta app (Tech Provider); each tenant authorizes via Facebook Login for Business / WhatsApp Embedded Signup. One global webhook URL; events are routed to a tenant **only by the signature-verified asset ID**. Unknown assets are stored as `ignored`.
- The Graph client obtains credentials through a per-tenant `MetaCredentialProvider`, so manual → OAuth changes the provider, not the callers.
- All webhook-processing jobs are tenant-aware (payload carries `tenant_id`) and refuse to run if the event's tenant and the integration's tenant differ.
- Not implemented yet: this document describes Phase 2/3 requirements.

## 1. Accounts & business prerequisites (owner action required)

| # | Prerequisite | Why | Owner |
|---|---|---|---|
| 1 | **Meta Business Portfolio** (Business Manager) for Shvet Technologies, with at least two admins | Owns the app, Page, ad account, WABA and system user | Shvet admin |
| 2 | **Business Verification** completed in Business Settings → Security Center | Required for Advanced Access to permissions, higher WhatsApp limits, and display-name approval | Shvet admin (needs GST/registration documents) |
| 3 | **Facebook Page(s)** that run lead ads, owned by the business portfolio; Instagram professional account linked to the Page | Lead Ads (Facebook and Instagram placements) are delivered via the Page | Marketing |
| 4 | **Ad Account** in the business portfolio | Campaign reporting | Marketing |
| 5 | **Meta Developer App** of type *Business*, owned by the business portfolio, with products: *Webhooks*, *WhatsApp*, *Marketing API* (optional), *Facebook Login for Business* (optional, only if OAuth reconnect is wanted) | API access | Developer + Shvet admin |
| 6 | App settings: Privacy Policy URL, Terms URL, app icon, data-deletion instructions URL, business use description | Required for App Review / Live mode | Shvet |
| 7 | **WhatsApp Business Account (WABA)** in the portfolio, with a **dedicated phone number** that can receive SMS/voice OTP. The number must not be active on the WhatsApp consumer/Business app unless Meta's "coexistence" onboarding is used deliberately (verify current availability). | Cloud API sender | Shvet admin |
| 8 | Approved **display name** for the number; two-step verification **PIN** set at registration (store securely, not in the CRM) | Number registration | Shvet admin |
| 9 | **Payment method** on the WABA (Business Settings → WhatsApp accounts → Payment) | Billable business-initiated (template) messages | Finance |
| 10 | **System User** (Admin role) in Business Settings → Users → System users, assigned to the App, Page(s) (full control or leads access), Ad Account (view performance), and WABA (full control) | Long-lived server token (recommended credential) | Shvet admin |
| 11 | **Leads Access Manager**: in Business Settings → Integrations → Leads access, grant the CRM app/system user access to the Page's leads (if lead access restrictions are enabled) | Otherwise lead retrieval returns permission errors | Shvet admin |
| 12 | Public **HTTPS domain** with valid TLS certificate (e.g., `crm.shvettech.com`) | Meta only calls HTTPS webhook URLs with valid certificates | Shvet IT |
| 13 | Privacy notice on lead forms & website that names WhatsApp/phone follow-up; lead-form custom disclaimer/consent checkbox where marketing messages are intended | Consent evidence (DPDP Act, WhatsApp opt-in policy) | Shvet + counsel |

---

## 2. Credentials the CRM needs

All secrets live in `.env` (server-only) **or** are entered on the Admin Integrations page and stored encrypted (`APP_KEY`-based AES-256 via Laravel `encrypted` cast). They are never sent to the browser, never logged, and the UI only shows "configured ✓ / last 4 chars".

| Variable / setting | Source | Secret? | Used for |
|---|---|---|---|
| `META_APP_ID` | App Dashboard → Settings → Basic | No | API calls, `debug_token` |
| `META_APP_SECRET` | App Dashboard → Settings → Basic | **Yes** | Webhook signature verification (`X-Hub-Signature-256`), `appsecret_proof` |
| `META_WEBHOOK_VERIFY_TOKEN` | Random string we generate (≥ 32 chars) | **Yes** | Webhook GET verification challenge |
| `META_GRAPH_VERSION` | Current stable version at setup time (e.g. `v2x.0`) | No | Pinned API version; upgrade deliberately before deprecation |
| Meta System User access token | Business Settings → System users → Generate token (select app + scopes) | **Yes** | Lead retrieval, page subscription, insights, WhatsApp |
| Page access token(s) | Derived via `GET /me/accounts` or `GET /{page-id}?fields=access_token` with the system user token | **Yes** (stored encrypted in `meta_pages`) | Lead retrieval & page subscription |
| Ad Account ID (`act_…`) | Ads Manager / Business Settings | No | Insights sync |
| `WHATSAPP_BUSINESS_ACCOUNT_ID` (WABA ID) | WhatsApp Manager / App Dashboard → WhatsApp → API Setup | No | Templates, subscriptions |
| `WHATSAPP_PHONE_NUMBER_ID` | App Dashboard → WhatsApp → API Setup | No | Sending messages, media |
| WhatsApp access token | Same System User token (with WhatsApp scopes) | **Yes** | Cloud API calls |

Do **not** use the 24-hour temporary token from the API Setup page in production.

---

## 3. Permissions (scopes) on the System User token

| Permission | Needed for | Phase |
|---|---|---|
| `pages_show_list` | List Pages the system user can access | 2 |
| `pages_read_engagement` | Read Page metadata | 2 |
| `pages_manage_metadata` | Subscribe the app to the Page's `leadgen` webhook field | 2 |
| `pages_manage_ads` | Read lead forms (`/{page-id}/leadgen_forms`) | 2 |
| `leads_retrieval` | Read lead field data (`/{leadgen-id}`) | 2 |
| `ads_read` | Campaign/ad set/ad names and insights | 2 (names), 4 (insights) |
| `business_management` | Read business assets (Pages, ad accounts, WABAs) | 2 |
| `whatsapp_business_management` | WABA/phone number info, templates, subscribed apps | 3 |
| `whatsapp_business_messaging` | Send messages, media upload/download | 3 |

Notes:
- For a single business using its **own** assets via a System User, Standard Access is often sufficient; **Advanced Access (App Review)** is required if the app accesses assets of businesses it does not own. Confirm the current rule in App Dashboard → App Review → Permissions and Features before going live.
- The app must be switched to **Live** mode for production webhooks to deliver real (non-test) data.

---

## 4. Webhook configuration

### 4.1 Endpoints (exposed by the CRM)

| URL | Object | Fields to subscribe |
|---|---|---|
| `https://<domain>/webhooks/meta` | `page` | `leadgen` |
| `https://<domain>/webhooks/whatsapp` | `whatsapp_business_account` | `messages` (covers inbound messages **and** status updates). Optional later: `message_template_status_update`, `account_update`, `phone_number_quality_update`. |

Both endpoints: CSRF-exempt, rate-limited, no session, raw body preserved.

### 4.2 Configuration steps
1. App Dashboard → Webhooks → select object **Page** → callback URL + verify token → subscribe to `leadgen`.
2. Subscribe the app to each Page: `POST /{page-id}/subscribed_apps?subscribed_fields=leadgen` (with the Page token). The CRM does this from the Integrations page and records `leadgen_subscribed_at`.
3. App Dashboard → WhatsApp → Configuration → callback URL + verify token → subscribe to `messages`.
4. Subscribe the app to the WABA: `POST /{waba-id}/subscribed_apps`. Verify with `GET /{waba-id}/subscribed_apps`.

### 4.3 Verification (GET)
Meta sends `hub.mode=subscribe`, `hub.verify_token`, `hub.challenge`. The CRM responds `200` with the raw `hub.challenge` only if mode is `subscribe` and the token matches (constant-time compare); otherwise `403`.

### 4.4 Signature verification (POST)
Header `X-Hub-Signature-256: sha256=<hex>`; expected = `hash_hmac('sha256', $rawBody, META_APP_SECRET)`; compare with `hash_equals`. Reject (and do not process) missing/invalid signatures. Signature is computed over the exact raw bytes — the body must not be re-encoded before verification.

### 4.5 Delivery semantics we design for
- Meta may deliver the same event more than once, batch several entries in one POST, deliver out of order, and retry for a period if we do not answer `200` quickly. → store-then-ack, per-change dedupe keys, asynchronous jobs.
- A `leadgen` change contains IDs (`leadgen_id`, `page_id`, `form_id`, `ad_id`, `adgroup_id`, `created_time`) but **not** the person's answers. Answers must be fetched via `GET /{leadgen_id}` with an authorized token. The payload alone is never trusted to create a lead.
- WhatsApp status updates (`sent`, `delivered`, `read`, `failed`) may arrive before our send job has stored the returned `wamid`, or out of order.

---

## 5. Meta Lead Ads — API calls (Phase 2)

| Purpose | Call |
|---|---|
| Validate token, scopes, expiry | `GET /debug_token?input_token=…` (app token `app_id|app_secret`) |
| List Pages | `GET /me/accounts` (or business-owned pages via `/{business-id}/owned_pages`) |
| List lead forms | `GET /{page-id}/leadgen_forms?fields=id,name,status,questions` |
| Subscribe Page | `POST /{page-id}/subscribed_apps?subscribed_fields=leadgen` |
| Fetch a lead | `GET /{leadgen-id}?fields=id,created_time,field_data,ad_id,ad_name,adset_id,adset_name,campaign_id,campaign_name,form_id,is_organic,platform` (fields not returned for a given lead are stored as null/unknown) |
| Backfill (recovery) | `GET /{form-id}/leads?filtering=[{field:"time_created",operator:"GREATER_THAN",value:…}]` |
| Test | Meta **Lead Ads Testing Tool** (developers.facebook.com/tools/lead-ads-testing) |

Field mapping: standard question keys (`full_name`, `first_name`, `last_name`, `phone_number`, `email`, `company_name`, `job_title`, `city`) map automatically; custom questions are mapped per form by an Admin; unmapped answers are attached as a sanitized note.

Error handling: `190` (token invalid/expired) → mark integration `token_invalid`, alert Admins, pause jobs; `10`/`200`-range permission errors → permanent failure with a clear message; `4`/`17`/`32`/`613` and `X-Business-Use-Case-Usage` / `X-App-Usage` headers → back off and retry; 5xx/timeouts → retry with backoff.

## 6. Marketing API — campaign reporting (Phase 4, optional)

- `GET /act_{ad-account-id}/campaigns?fields=id,name,objective,status`
- `GET /act_{ad-account-id}/insights?level=campaign&time_increment=1&time_range={since,until}&fields=campaign_id,campaign_name,spend,impressions,reach,clicks,actions`
- Daily rows are upserted into `campaign_metrics`; the last ~28 days are re-fetched nightly because Meta revises recent data. Meta-reported numbers (spend, impressions, clicks, Meta-counted leads) are displayed separately from CRM-calculated numbers (leads received, qualified, won, revenue, cost per CRM lead, cost per won deal). Attribution windows of Meta metrics are shown as reported, not reinterpreted.
- Requires `ads_read` and the System User assigned to the ad account. If not granted, the Campaigns page shows CRM-only data and a "Meta reporting not connected" state.

## 7. WhatsApp Cloud API (Phase 3)

### 7.1 Calls
| Purpose | Call |
|---|---|
| Connection test (no message sent) | `GET /{phone-number-id}?fields=display_phone_number,verified_name,quality_rating,code_verification_status,platform_type`; `GET /{waba-id}?fields=name`; `GET /{waba-id}/subscribed_apps`; `GET /{waba-id}/message_templates?limit=1`; `debug_token` for scopes |
| Send text / template / media | `POST /{phone-number-id}/messages` with `messaging_product: "whatsapp"` |
| Upload media | `POST /{phone-number-id}/media` (multipart) → media ID |
| Download inbound media | `GET /{media-id}` → short-lived URL → `GET` URL with bearer token → store on private disk |
| Mark read | `POST /{phone-number-id}/messages` with `status: "read", message_id` |
| Templates | `GET /{waba-id}/message_templates?fields=name,language,status,category,components` (only `APPROVED` usable) |

### 7.2 Messaging rules the CRM enforces
- **Customer service window**: free-form messages only within 24 h of the customer's last inbound message (`last_inbound_at`). Outside the window the composer switches to approved templates only. (Meta's rules/pricing — per-message pricing since 2025 — must be re-verified at setup.)
- Templates: only `APPROVED` templates for the configured language; parameter count/format validated before send; template category shown (Marketing/Utility/Authentication).
- **Marketing** templates blocked when the contact has no granted marketing consent or has opted out. Inbound "STOP"/"UNSUBSCRIBE" (configurable keywords) records an opt-out.
- No automatic messaging to new leads; every outbound message is a deliberate user action (or a later, explicitly approved automation).
- Respect Cloud API throughput and per-number messaging limits; on rate-limit errors (`130429`, `131056`, `80007`) back off rather than retry tightly. Error `131047` (outside window) and `131026` (undeliverable) surface clearly in the UI.
- Media limits (verify current values): images JPEG/PNG ≤ 5 MB; video MP4/3GPP ≤ 16 MB; audio AAC/MP4/MPEG/AMR/OGG ≤ 16 MB; documents (PDF, Office, TXT) ≤ 100 MB (CRM default cap 16 MB); stickers WebP. Server validates real MIME type (finfo), not just extension.

### 7.3 Attribution
Inbound messages from **Click-to-WhatsApp ads** include a `referral` object (`source_type`, `source_id` = ad ID, `source_url`, `headline`, and `ctwa_clid`). The CRM stores it as an attribution touch (first touch only if the lead is new). Without a referral object, attribution is "WhatsApp — organic/unknown".

---

## 8. Token lifecycle
- Daily scheduled health check calls `debug_token`; stores `expires_at`, `is_valid`, granted scopes; warns Admins 14 days before expiry (if an expiring token is used) and immediately on invalidation (password change, permission removal, user removed from business, app secret reset).
- Disconnect = delete stored tokens, unsubscribe Page/WABA (best effort), keep historical data; audited.
- Reconnect = enter new token (or OAuth), re-validate scopes, re-subscribe, then backfill leads since the last successful webhook using the form leads endpoint.

## 9. Security checklist for integrations
- [ ] App secret, verify token and access tokens only in env / encrypted DB columns; `.env` never committed.
- [ ] `appsecret_proof` sent with Graph calls (enable "Require App Secret" in app advanced settings).
- [ ] Webhook signature + verify token checked; invalid requests rejected and counted.
- [ ] Raw payloads encrypted, short retention; logs contain only IDs and outcomes.
- [ ] Outbound HTTP: TLS verification on, 10 s connect / 30 s total timeout, bounded retries.
- [ ] Credential changes and disconnects written to `audit_logs`.

## 10. Real-account test plan (staging, before go-live)
1. Lead Ads Testing Tool → create test lead → CRM lead appears once; resend same lead → no duplicate.
2. Tamper a webhook signature (curl) → 403, no event stored.
3. WhatsApp: message the business number from a test handset → conversation + lead link prompt; reply free-form → delivered/read ticks; wait > 24 h (or use a fresh number) → composer requires template; send approved template → statuses update.
4. Revoke the token in Business Settings → integration shows `token_invalid` within the next health check, jobs pause, Admin notified.
5. Stop queue workers, send several events, restart → all processed exactly once.

## 11. Open questions for Shvet
1. Which Facebook Page(s), ad account and phone number will be used? Is the phone number currently on the WhatsApp Business app?
2. Is Business Verification already complete?
3. Which WhatsApp templates (and languages — English, Hindi, Gujarati?) should be prepared for approval?
4. Should Click-to-WhatsApp ads be part of the campaign plan (enables WhatsApp attribution)?
5. Who in Shvet is the Meta Business admin who will create the System User and token?

---

> **Phase 3 update.** Meta Lead Ads is implemented with per-tenant **OAuth** only; the manually entered System User token option from the Phase 0 decisions is *not* implemented (see `docs/PHASE_3_META_LEAD_ADS.md` §10). The phase numbering above predates the delivered phases (Phase 2 = core CRM, Phase 3 = Meta Lead Ads). Meta-specific requirements and every unverified assumption are maintained in the Phase 3 document, not here.
