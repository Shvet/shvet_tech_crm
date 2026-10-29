# Phase 3 — Meta Facebook & Instagram Lead Ads integration

Status: implemented in the working tree and verified **against mocked Meta responses only**. It has **not** been run against a real Meta app, Page or lead. Nothing in this document may be read as "works with live Meta" until the real-account checklist in §11 has been completed.

Baseline: Phase 2 (uncommitted in the working tree at the time of writing); remote branch head `e7d8cf2` (Phase 1a + a Dependabot CI bump).

---

## 1. Meta prerequisites (what must exist before a tenant can connect)

> **Verification status.** The authoring environment could not reach `developers.facebook.com` (network allow-list), so none of the items below were checked against Meta's current official documentation. They reflect Meta's documented behavior as best known and a single secondary web search (see §12). **Every row marked ⚠ must be re-checked against the current official docs before go-live.** Meta changes permission names, review rules and Graph versions regularly.

### 1.1 The platform Meta app (one per CRM deployment, not per tenant)

| # | Requirement | Notes |
|---|---|---|
| 1 | A **Meta developer app** of type *Business*, owned by a verified Meta Business Portfolio | Shvet Technologies owns it; customers (tenants) authorize *their* Pages to this app. |
| 2 | Products added: **Facebook Login** (or *Facebook Login for Business*) and **Webhooks** | Login provides the OAuth dialog; Webhooks delivers `leadgen` events. ⚠ Which login product is current/preferred for Page + lead access must be confirmed. |
| 3 | **Valid OAuth Redirect URI** registered: `https://<crm-host>/integrations/meta/callback` | Must match `META_OAUTH_REDIRECT_URI` exactly (scheme, host, path). The CRM never accepts a redirect URI from the browser. |
| 4 | **Webhooks → Page object → callback URL** `https://<crm-host>/webhooks/meta`, **verify token** = `META_WEBHOOK_VERIFY_TOKEN`, subscribed field **`leadgen`** | Callback must be public HTTPS with a valid certificate. |
| 5 | App settings completed: Privacy Policy URL, Terms, app icon, data-deletion instructions URL, business use description | Required for App Review and Live mode. |
| 6 | **Business Verification** of the owning business | ⚠ Required by Meta for Advanced Access to the lead/page permissions when other businesses' assets are accessed (which is exactly the multi-tenant SaaS case). |
| 7 | **App Review → Advanced Access** for the permissions in §1.2 (with screencast and use-case text) | ⚠ Without it the app only works for people with a role on the app (admins/developers/testers) on assets they control. |
| 8 | App switched from **Development** to **Live** mode | ⚠ In Development mode Meta only delivers webhooks/leads for app-role users' assets and test leads. |

### 1.2 Permissions requested from each tenant admin (least privilege)

Configured in `META_OAUTH_SCOPES` (comma-separated) and **validated server-side after login** against what Meta says was actually granted (`/debug_token`), never against what we asked for.

| Permission | Purpose | Status |
|---|---|---|
| `pages_show_list` | List the Pages the admin manages (`/me/accounts`) | ⚠ |
| `pages_read_engagement` | Read basic Page data returned with Page tokens | ⚠ |
| `pages_manage_metadata` | Subscribe the app to the Page's `leadgen` webhook field (`POST /{page-id}/subscribed_apps`) | ⚠ |
| `leads_retrieval` | Read lead field data (`GET /{leadgen-id}`) and webhooks for leads | ⚠ |
| `pages_manage_ads` | Read the Page's lead forms (`GET /{page-id}/leadgen_forms`) | ⚠ **Discrepancy:** Meta's own webhooks guide was reported (secondary source) to list `ads_management` where older guides list `pages_manage_ads`. The default below follows the older, narrower list; if Meta rejects form listing, change `META_OAUTH_SCOPES`/`META_REQUIRED_PERMISSIONS` after confirming against the current docs. |

Not requested (deliberately): `business_management`, `ads_read`, `ads_management`, `public_profile` beyond Meta's default, any messaging permission.

### 1.3 Page and lead access requirements for the tenant

- The connecting Facebook user must be able to **manage the Page** (have a task such as `MANAGE`/`CREATE_CONTENT` on it) — Meta only returns Page access tokens for Pages the user administers.
- If the business enabled **lead access restrictions**, the connecting user/app must be granted access in *Business Settings → Integrations → Leads Access* ⚠. Otherwise lead retrieval fails with a permission error; the CRM surfaces this as an actionable message.
- Lead forms must belong to that Page. Instagram lead ads are delivered through the linked Facebook Page's lead forms (`platform` = `ig` on the lead) ⚠.

### 1.4 Token behavior (assumed; ⚠ verify)

1. The OAuth code is exchanged **server-side** for a short-lived user token; that is exchanged for a **long-lived user token** (`grant_type=fb_exchange_token`).
2. Page access tokens obtained with a long-lived user token are documented as **not expiring by time**, but they become invalid when the user changes their password, loses the Page role, removes the app, or permissions are revoked. Meta also applies **data access expiry** (approximately 90 days since the user last used the app) after which re-authorization is needed.
3. The CRM therefore stores **only Page tokens (encrypted)**, never the user token, and runs a daily `debug_token` check per connection. Any Graph error `190` (or equivalent revoked/expired state) flips the connection to `reauthorization_required`, pauses imports for it and shows a banner to the Admin.
4. Disconnecting deletes the stored tokens and best-effort unsubscribes the Page (`DELETE /{page-id}/subscribed_apps`). It does **not** revoke the user's whole app authorization (that would also affect other uses); the Admin can remove the app in Facebook settings.

### 1.5 Graph API version

`META_GRAPH_VERSION` is **required configuration with no default in code**. `.env.example` shows `v25.0` — the newest version announced in the sources reviewed at authoring time — but it is an example, not an assertion that it is currently supported. Set it from Meta's changelog and re-check before each Meta deprecation date. When unset, the integration refuses to start ("Meta Graph API version is not configured") and `crm:preflight` reports it once any Meta credential is set.

---

## 2. What was built

| Area | Implementation |
|---|---|
| Per-tenant connection | Official OAuth only (`GraphClient::authorizationUrl` → Facebook dialog → `/integrations/meta/callback`). Admin-only (`integrations.manage`). Manual System User token entry is **not** implemented in Phase 3 (see §10). |
| Pages & forms | After login the admin picks Pages from a server-held list; forms are fetched per Page and appear **disabled and unmapped**. |
| Mapping | Per form: lead source, starting stage, default owner, optional question-key overrides. All references are same-tenant (validated in the request, re-checked in the action, enforced by composite foreign keys). |
| Webhook | `GET/POST /webhooks/meta` (no session/CSRF). Handshake, raw-body HMAC-SHA256 verification (constant time), size cap, persist-then-dispatch, idempotent on `(page_id, leadgen_id)`. |
| Import | Queue job `ProcessMetaLeadEvent` → `ImportMetaLead` → fetch the full lead from Graph → `MetaLeadMapper` → **`CreateLead::import()`** (the existing Phase 2 action, shared persistence path). |
| Duplicates | Same phone/e-mail as an existing lead ⇒ event becomes **needs_review**; nothing is created or changed. Admin: *Create anyway* or *Dismiss*. |
| Recovery | `meta:recover-events` (every minute), per-event Retry/Replay in the UI, `failed()` parking, stuck-`processing` reset. |
| Health | `meta:validate-connections` daily (`debug_token` on a stored Page token); invalid ⇒ `reauthorization_required`, imports pause, UI banner. |
| Retention | `meta:prune` daily; events and OAuth state older than `META_RETENTION_DAYS` (default 90) are deleted. |
| Audit | `meta.connection.{started,created,reauthorized,cancelled,disconnected,reauthorization_required}`, `meta.page.{connected,disconnected}`, `meta.mapping.updated`, `meta.lead.{imported,needs_review}`, `meta.event.{replayed,duplicate_confirmed,dismissed}`, `meta.webhook.rejected` (rate-limited to one row/minute/IP). |

### 2.1 Data model (migration `2026_10_29_000100_create_meta_integration_tables`)

| Table | Purpose / key constraints |
|---|---|
| `meta_connections` | One per (tenant, Meta user): `UNIQUE(tenant_id, meta_user_id)`; status; granted permissions; expiries; sanitized last error; `pending_assets` (**encrypted**, short-lived Page list incl. tokens). |
| `meta_pages` | `page_id` **globally UNIQUE** (a Page belongs to exactly one tenant ⇒ unambiguous webhook routing); `access_token` **encrypted**; composite FK to the connection. |
| `meta_form_mappings` | `UNIQUE(tenant, page, form)`; composite FKs to `lead_sources`, `pipeline_stages`, `tenant_memberships`; `CHECK (is_enabled = 0 OR (source AND stage))`. |
| `meta_lead_events` | One row per webhook entry, **identifiers only (no lead PII)**; `UNIQUE(page_id, leadgen_id)`; `tenant_id` nullable (quarantine); status, attempts, sanitized failure, warnings (codes), correlation id, matched/created lead ids. |
| `meta_oauth_states` | Hashes only (`state_hash` unique, `nonce_hash`), expiry, `used_at`. |
| `leads` (+2 cols) | `external_provider`, `external_id`, `UNIQUE(tenant_id, external_provider, external_id)` ⇒ a Meta lead can exist at most once per tenant, enforced by the database. |
| `lead_activities.user_id` | Made nullable so integration-authored notes have no fake human author. |

## 3. Security design

### 3.1 OAuth
- `state = <48-char random>.<HMAC-SHA256(token | tenant | user, APP_KEY)>`. The database stores only `sha256(token)` and `sha256(nonce)`; the nonce also lives in the initiating browser session.
- The callback accepts a state only if **all** hold: valid HMAC, row found in the *current* tenant (global scope, tenant from the validated session — never from the request), same user, unused, unexpired (10 min), nonce matches the session, and the atomic `UPDATE … WHERE used_at IS NULL` affected one row. Every failure returns one generic message (no oracle).
- Redirect URI comes from `META_OAUTH_REDIRECT_URI` (or `APP_URL`), never from the request. The authorization code is exchanged server-side; the code/tokens never reach the browser, audit log or logs.
- The short-lived token is exchanged for a long-lived one, inspected with `/debug_token` (permissions are verified against what Meta says was **granted**, not what we asked for), used once to list Pages, then **discarded**. Only Page tokens are stored (encrypted cast, `APP_KEY`).
- The browser never supplies a Page id/token: the *select Pages* step accepts only ids that exist in the server-side encrypted blob (30 min TTL).

### 3.2 Webhook
- Signature: `hash_equals('sha256='.hmac_sha256(raw_body, META_APP_SECRET), X-Hub-Signature-256)` over the **raw** body. Missing/unconfigured secret ⇒ 503 (fail closed; an empty key is never accepted). Wrong/missing signature ⇒ 403, nothing stored.
- Body limit `META_WEBHOOK_MAX_BYTES` (default 256 KiB) checked on `Content-Length` and actual length ⇒ 413. At most `META_WEBHOOK_MAX_CHANGES` (100) changes per delivery.
- Ids must be numeric strings (`/^\d{1,40}$/`); anything else is dropped before reaching a column or URL path.
- Tenant resolution: only from our own `meta_pages` row for the Page id. Payload fields such as `tenant_id` are ignored. Unknown Page ⇒ `quarantined` (no tenant, never dispatched, pruned after retention).
- Persist before 200: a database failure yields 500 so Meta redelivers. Dispatch failure does **not** fail the request (the event is durable; `meta:recover-events` redispatches).
- Rate limits: named limiters `meta-webhook` (600/min/IP), `meta-webhook-verify` (30/min/IP), `meta-admin` (20/min/user for admin actions that call Meta).

### 3.3 Isolation
Route binding for `{metaConnection}`, `{metaPage}`, `{metaMapping}`, `{metaEvent}` is tenant-scoped (foreign ids ⇒ 404). Jobs carry `tenantId` + the event's public id only, pass through `SetTenantContext` (suspended/missing tenant ⇒ job fails and the event is parked), and the importer re-validates connection status, Page, subscription and mapping at run time. Composite foreign keys make cross-tenant references impossible at the database level (tested).

### 3.4 Secrets & logs
Tokens travel only in the `Authorization` header (+ `appsecret_proof`); the single unavoidable exception is Meta's code-exchange call, whose URL is never logged. `MetaApiException` carries codes/categories only — never URLs, tokens, request bodies or Meta's free-text messages (which can echo user data). All `MetaLog` context passes through `Redactor`. Inertia props are an explicit allow-list (no token, pending blob or raw payload).

## 4. Duplicate & idempotency policy (defined, documented, recorded)

| Situation | Outcome | Recorded as |
|---|---|---|
| Same webhook delivered again | Collapsed by `UNIQUE(page_id, leadgen_id)`; no second job | — |
| Same event processed twice (job redelivered) | Atomic claim `received → processing`; second run is a no-op | `attempts` |
| Crash after the lead commit, before the event is settled | Retry finds the lead by `(tenant, meta, leadgen_id)` and settles the event | `imported / already_imported` |
| Two workers race to create the same Meta lead | DB unique key; loser reads the winner's lead | `imported / already_imported` |
| Phone or e-mail matches an existing lead (any owner, incl. archived) | **No lead created, nothing overwritten.** Admin decides | `needs_review / possible_duplicate`, `matched_lead_id` |
| Admin chooses *Create anyway* | New lead created on the next run | audit `meta.event.duplicate_confirmed` |
| Admin chooses *Dismiss* | Closed, no lead | audit `meta.event.dismissed` |

Imported leads are never merged with, or used to update, existing leads. Invalid phone/e-mail values are dropped (not stored raw) and noted as warning codes; extra form answers go to a note (≤25 answers × 500 chars, control characters stripped).

## 5. Operations

### 5.1 Environment variables
| Variable | Required | Notes |
|---|---|---|
| `META_APP_ID`, `META_APP_SECRET` | yes | From the Meta app. Secret is server-only. |
| `META_WEBHOOK_VERIFY_TOKEN` | yes | ≥24 random chars (`crm:preflight` enforces once Meta is configured). |
| `META_GRAPH_VERSION` | yes | **No default.** `.env.example` shows `v25.0` as an *unverified example*. |
| `META_OAUTH_REDIRECT_URI` | no | Defaults to `APP_URL/integrations/meta/callback`; must be https in production and registered in the Meta app. |
| `META_OAUTH_SCOPES`, `META_REQUIRED_PERMISSIONS` | no | See §1.2. |
| `META_GRAPH_BASE_URL`, `META_OAUTH_DIALOG_BASE_URL` | no | Override only for mock servers/tests. |
| `META_RETENTION_DAYS` (90), `META_WEBHOOK_MAX_BYTES`, `META_WEBHOOK_MAX_CHANGES`, `META_JOB_TRIES` | no | See `config/meta.php`. |

### 5.2 Workers and scheduler
- A **real queue worker is required** (`QUEUE_CONNECTION=redis|database`; `sync` would import inside the webhook request — preflight warns). Worker command: `php artisan queue:work --tries=6 --max-time=3600` under Supervisor; `queue:restart` on deploy.
- Cron: `* * * * * php artisan schedule:run`. Scheduled: `meta:recover-events` (1 min), `meta:validate-connections` (03:17), `meta:prune` (03:47). `withoutOverlapping` + `onOneServer` need a shared cache store (Redis) in multi-server setups.

### 5.3 Retry policy
Retryable Graph errors (timeouts, 5xx, rate limits, unknown) are retried up to `META_JOB_TRIES` (6) with delays 30 s, 2 min, 10 min, 30 min, 1 h (+ up to 20 % jitter), never shorter than Meta's own `Retry-After` / `estimated_time_to_regain_access`, capped at 1 h. Non-retryable errors (auth, permission, invalid, not found) park the event immediately as `failed` with a sanitized, actionable message. Auth errors additionally flip the connection to `reauthorization_required`. After the last try the event is parked as `failed` (replayable).

### 5.4 Token expiry & reauthorization
Daily `meta:validate-connections` plus any runtime auth error (code 190) set `reauthorization_required`; imports for that connection fail fast (`connection_inactive`, replayable). An admin reconnects (same Meta account ⇒ the same connection row, existing Page tokens refreshed, mappings kept); validation then returns the connection to `active`. Then use **Retry** on failed events.

### 5.5 Troubleshooting
| Symptom | Likely cause / action |
|---|---|
| "Meta is not configured" | Missing env vars listed in the UI; `php artisan crm:preflight`. |
| Meta rejects the webhook URL | Public HTTPS + valid cert; `META_WEBHOOK_VERIFY_TOKEN` equals the value in the Meta app; `curl 'https://host/webhooks/meta?hub.mode=subscribe&hub.verify_token=…&hub.challenge=1'` must echo `1`. |
| No leads arrive, Page shows a warning | Subscription failed: click *Retry subscription*; confirm `pages_manage_metadata`; app in Live mode; Page admin task. |
| Events `quarantined` | Page not connected in any workspace (or disconnected). Connect it; quarantined events are **not** replayed (data minimization) — ask the prospect to resubmit or use Meta's lead export. |
| Event `failed: meta_permission_*` | Missing `leads_retrieval`/lead-access grant (§1.3). Fix, then *Retry*. |
| Event `ignored` | The form has no enabled mapping. Map it, then *Retry*. |
| Event `needs_review` | Possible duplicate; *Create anyway* or *Dismiss*. |
| Events stuck `received` | Worker not running; `meta:recover-events` will re-dispatch after 5 min. |
| Developer testing | `php artisan meta:simulate-webhook <page_id> <leadgen_id> --form_id=<id>` (non-production only; **mock**, exercises our endpoint, not Meta). |

## 6. Privacy and data handling (engineering notes — not legal advice)
- **Personal data** enters via Meta leads (name, e-mail, phone, free-text answers). It is stored only as a normal CRM lead (+ a note); the `meta_lead_events` table keeps **identifiers only**.
- **Minimization:** requested permissions are limited to §1.2; user tokens are discarded; invalid contact values are dropped; webhook payloads are not stored raw.
- **Retention:** events/OAuth state: `META_RETENTION_DAYS`. Leads follow the CRM's own lifecycle (archive/delete) — Phase 3 adds no automatic lead deletion.
- **Disconnect:** unsubscribes (best effort), deletes Page tokens and mappings immediately, keeps leads and history. A Meta data-deletion request flow is **not** implemented (a data-deletion callback/URL is a Meta app-review requirement — see go/no-go).
- **Logs/audit:** structured, redacted, identifiers only; no lead fields, tokens or codes.
- Consent, lawful basis, notices, cross-border transfer and DPDP/GDPR obligations remain the customer's and Shvet's responsibility and need legal review. Nothing here asserts compliance.

## 7. Testing

| Layer | What | Result |
|---|---|---|
| PHPUnit (`tests/Feature/Meta`) | Webhook handshake/signature/size/idempotency/quarantine/tenant resolution; OAuth state (missing, forged, replayed, expired, wrong user, wrong tenant, wrong session); page selection; mapping validation + DB constraints; import (success, mapping, invalid data, duplicates, concurrency, retries, rate limit, auth/permission errors, suspended tenant, disconnect, replay); recovery/prune/validate commands; Graph client classification and secrecy | 414/414 PHPUnit tests pass (full suite incl. Phases 1–2) |
| Vitest | Admin page: permission gating, setup state, connect, page picker, mapping validation, reauth banner, confirmations, history actions, no token rendering | 73/73 |
| Browser | `e2e/meta.cjs` against a **mock Graph server** (`e2e/mock-graph-server.cjs`; setup in the file header) | 13/13; existing suites still 27/27 and 18/18 |

**All of the above use mocked Meta responses.** Fixtures in `tests/Fixtures/meta` are hand-written approximations of Meta's documented payloads, not captures from a real app. They prove our code's behavior under those assumptions; they do not prove that Meta behaves that way.

Security tests were checked by mutation: each protection (signature check, verify token, size limit, fail-closed secret, OAuth single-use/expiry/user/nonce/HMAC, token encryption, admin-only policy, duplicate detection, idempotency keys, tenant validation, claim, connection checks, token-in-header, secret-free errors, retry cap, recovery window) was removed in turn and the suite was required to fail. Result: every protection is caught by at least one test, except three mutations that are equivalent or purely redundant (the `CreateLead::import` pre-check, which the database unique key + 1062 handler backs up; a no-op mutation of the scheduler tenant loop; a message mutation that cannot leak because the exception never holds secrets).

## 8. Go / no-go checklist (Meta integration)

| # | Check | Type |
|---|---|---|
| 1 | All ⚠ items in §1 re-verified against Meta's current official docs; permission names, Graph version, endpoint fields and webhook payload shape confirmed | **Blocker** |
| 2 | §11 real-account checklist completed on a **test** Page with a **test** lead (Meta's Lead Ads Testing Tool) | **Blocker** |
| 3 | Meta Business Verification + App Review (Advanced Access) done for every permission, app in Live mode, privacy-policy and **data-deletion** URL/callback in place | **Blocker** for any customer other than app role-holders |
| 4 | `crm:preflight` exits 0 with Meta env set; webhook URL is public HTTPS | Blocker |
| 5 | Queue worker + scheduler running; a failed-job alert exists; `meta:recover-events` observed re-dispatching a deliberately orphaned event | Blocker |
| 6 | `APP_KEY` backed up (Page tokens are unreadable without it) | Blocker |
| 7 | Runbook for reauthorization and replay read by support | Should |
| 8 | Privacy notice / retention reviewed by counsel | Blocker for real customer data |
| 9 | Larastan/static analysis and CI (see README) green | Should |

## 9. Assumptions and unverified behavior (complete list)
1. Endpoints, fields, error codes and headers listed in `GraphClient`/`MetaApiException` follow Meta's documented API as understood at authoring time; **none was verified live or against official docs** (network blocked).
2. Facebook Login (not *Facebook Login for Business*) with the §1.2 permissions is sufficient; `pages_manage_ads` vs `ads_management` for form listing is unresolved.
3. Page tokens obtained from a long-lived user token do not expire by time. If Meta behaves differently, the daily validation and runtime auth-error handling still flag reauthorization.
4. `debug_token` accepts a Page token and reports its scopes; `data_access_expires_at` is reported.
5. The webhook `leadgen` change carries `page_id`, `leadgen_id`, `form_id`, `ad_id`, `adgroup_id`, `created_time`; the lead object returns `field_data` with standard names (`full_name`, `email`, `phone_number`, `company_name`, …).
6. Meta redelivers on non-2xx and may deliver duplicates and out of order; we assume at-least-once delivery.
7. Instagram lead ads arrive through the linked Page with `platform = ig`.
8. Lead retention at Meta is limited (Meta's lead docs state leads are available for 90 days — see §13); older leads will be `not_found` when replayed.
9. Test-mode leads from Meta's testing tool are assumed to flow through the same path.

## 10. Known limitations / not in scope
- Manual System User token entry (Phase 0 decision #2 asked to *support* it): **not implemented**; OAuth only. The storage model (`meta_pages.access_token`) would accommodate it as a future "connection type".
- No historical backfill of leads created before connection, no polling fallback (webhooks only, plus per-event replay), no per-field custom mapping beyond the four standard targets, no Meta data-deletion callback, no 2FA for admins (platform-wide gap), single `leadgen` field only, no WhatsApp/billing (out of scope).
- Quarantined events are not auto-replayed after connecting the Page (by design, §6).
- The UI shows times in UTC.

## 11. Real-account verification checklist (must be done by a human with authorized Meta assets)
1. Create/confirm a Meta app (Business type), add Facebook Login + Webhooks, set the redirect URI and webhook callback/verify token; set `META_*` env; `php artisan crm:preflight`.
2. As a Meta app **tester/admin**, connect from *Integrations → Meta Lead Ads*; confirm all permissions are shown and granted; confirm `debug_token` scopes match.
3. Select a **test Page**; confirm subscription status "subscribed" and that the Page's *Webhooks* subscription lists `leadgen`.
4. Refresh forms; map one form; enable it.
   4b. If form listing or lead retrieval fails with a permission error, note the exact Graph error code (not the token) and test adding `ads_management` via `META_OAUTH_SCOPES` / `META_REQUIRED_PERMISSIONS` — record the outcome in §13.
   4c. The connecting Facebook user must be able to **advertise** on the Page (needed to subscribe the app to `leadgen`).
5. Use Meta's **Lead Ads Testing Tool** to create a test lead; confirm the webhook arrives (`meta_lead_events`), the job imports it, and the lead has the right name/phone/e-mail/source/stage/owner and note.
6. Send the same test lead twice; confirm one lead. Submit another with the same phone; confirm *needs review*.
7. Revoke the app in the Facebook user's settings; run *Check connection*; confirm the reauthorization banner and paused imports; reconnect; retry the failed event.
8. Stop the queue worker, submit a test lead, restart; confirm recovery.
9. Disconnect; confirm tokens/mappings are gone, the Page is unsubscribed in Meta, and leads remain.
10. Record Graph version, dates and any deviation from §1/§9 here and update this document.

## 12. Sources
Meta's official documentation could not be fetched from the authoring environment. Behavior above is based on prior knowledge of Meta's Marketing/Graph API and one secondary web search; treat every Meta-specific statement as **unverified** until checked against: Meta for Developers → *Graph API → Webhooks*, *Pages API → Leadgen*, *Facebook Login → Manually Build a Login Flow*, *Access Tokens → Debug*, *App Review → Permissions*, *Graph API Changelog* (versions).

## 13. Review against Meta documentation (2026-10-29)

**Method and limits.** `developers.facebook.com` is not reachable from the authoring environment (DNS fails: the host is not in the session's allowed network domains), so the official pages could **not** be opened in full. Findings below come from web-search excerpts of official Meta pages. Treat them as *indicative*, not as a full read of each page, and re-read the pages themselves before go-live. Nothing was tested against a live Meta app.

| Topic | Excerpt-level finding | Implementation | Status |
|---|---|---|---|
| Graph version | v25.0 changelog page says *released 18 Feb 2026*; the main changelog page excerpt still showed an older "latest", so whether a newer version exists was **not** determined. A third-party guide says v20.0 stops working 24 Sep 2026. | `META_GRAPH_VERSION` is required, no default; `.env.example` leaves it blank | Consistent; operator must pick and re-check |
| Webhook handshake | Verify request carries `hub.verify_token`, endpoint echoes `hub.challenge`, else 403 (Messenger/IAP webhook docs; same Webhooks product) | Same | Consistent |
| Webhook signature | `X-Hub-Signature-256: sha256=<HMAC-SHA256(raw body, app secret)>`; docs' sample hashes the **raw** buffer | Raw-body HMAC, constant-time compare | Consistent |
| Delivery semantics | Non-200 is an error and Meta retries; handlers should be idempotent. v25.0 changelog warns not to wait until the deadline. Exact retry schedule **not found**. | Persist-then-200, idempotent on `(page_id, leadgen_id)`, no Graph call in the request | Consistent; retry schedule unverified |
| Leadgen payload | `object: page` → `entry[].changes[]` with `field: leadgen`, `value`: `leadgen_id, page_id, form_id, adgroup_id, ad_id, created_time` (some examples omit `ad_id`/`adgroup_id`); IDs only, lead data fetched separately | Parser expects exactly this, tolerates missing optional ids | Consistent |
| Page subscription | `POST /{page-id}/subscribed_apps?subscribed_fields=leadgen` with a Page token **of someone who can advertise on the Page**; GET on same edge checks it | POST implemented; GET check not used | Consistent; add "advertiser role" prerequisite (done in §11) |
| Permissions | Webhooks page lists `leads_retrieval, pages_manage_metadata, pages_show_list, pages_read_engagement, ads_management`; the Retrieving Leads guide lists `leads_retrieval, pages_manage_ads, pages_read_engagement, pages_show_list, ads_management`. A quickstart contains the typo `lead_retrieval`. | Requested: `pages_show_list, pages_read_engagement, pages_manage_metadata, leads_retrieval, pages_manage_ads`; **`ads_management` is not requested** | **Open difference.** Official lists include `ads_management`. Whether it is required for lead retrieval / form listing could not be settled from excerpts. Decide on the test Page (§11 step 4b); the scopes are env-configurable. |
| Lead retrieval | `GET /{leadgen-id}` with a Page token; bulk by `/{form-id}/leads`; leads available 90 days; app must pass App Review (`leads_retrieval`, `pages_manage_ads`) and Business Verification | Per-lead GET implemented; no bulk backfill | Consistent |
| Token lifecycle | Short-lived → long-lived via `fb_exchange_token` (server-side); a Page token obtained from a **long-lived** user token has no expiry, but is invalidated by password change/security events; from a short-lived token it lasts ~1 h; expired tokens cannot be exchanged | Exchange then `/me/accounts`, store only Page tokens, daily `debug_token` + runtime 190 handling | Consistent |
| `data_access_expires_at` | Not documented in the excerpts; forum posts describe a ~3-month "data access" expiry that a token exchange does not extend | Read from `debug_token` if present, surfaced in UI | Unverified field |
| Auth header / proof | Docs show `Authorization: Bearer` for tokens and `appsecret_proof = HMAC-SHA256(token, app secret)`; "Require App Secret Proof" can be enabled in app settings | Bearer header + proof on every token call | Consistent; enable the app setting only after testing |
| Data deletion | Facebook Login apps must give a **data-deletion request callback URL** (HTTPS) or instructions URL. Request is a form POST with `signed_request`; the callback returns JSON `{url, confirmation_code}` (response shape from developer-forum excerpts, not an official page) | **Not implemented** | **Gap / launch blocker** (§14) |
| Webhooks mTLS | v25.0 blog excerpt: a Webhooks mTLS certificate update is planned for the next Graph version | None | Operational risk: confirm the TLS terminator will not reject Meta's client certificate when it changes |

### 13.1 Official sources consulted (search excerpts only)
- Graph API v25.0 changelog — https://developers.facebook.com/docs/graph-api/changelog/version25.0
- "Introducing Graph API v25 and Marketing API v25" — https://developers.facebook.com/blog/post/2026/02/18/introducing-graph-api-v25-and-marketing-api-v25
- Leads — Webhooks from Meta — https://developers.facebook.com/docs/graph-api/webhooks/getting-started/webhooks-for-leadgen/
- Retrieving Leads — https://developers.facebook.com/documentation/ads-commerce/marketing-api/guides/lead-ads/retrieving
- Lead Ads webhooks quickstart — https://developers.facebook.com/documentation/ads-commerce/marketing-api/guides/lead-ads/quickstart/webhooks-integration
- Lead Ads testing and troubleshooting — https://developers.facebook.com/documentation/ads-commerce/marketing-api/guides/lead-ads/testing-troubleshooting
- Permissions Reference — https://developers.facebook.com/docs/permissions
- Meta Webhooks for Messenger Platform (signature + handshake text) — https://developers.facebook.com/documentation/business-messaging/messenger-platform/webhooks
- Long-lived access tokens — https://developers.facebook.com/documentation/facebook-login/guides/access-tokens/get-long-lived
- Securing Graph API Requests (appsecret_proof) — https://developers.facebook.com/docs/graph-api/securing-requests
- Data-deletion callback: developer-forum threads only (https://developers.facebook.com/community/threads/1268480088629667/); the official Facebook Login settings page was not retrieved.

## 14. Production-readiness gaps found in review (open)
1. **Data-deletion callback** (and ideally a deauthorize callback) + public instructions page: required for Live mode; design: verify `signed_request` (HMAC-SHA256, app secret), match `user_id` to `meta_connections.meta_user_id`, run `DisconnectMeta` for every matching connection, return `{url, confirmation_code}`, audit it.
2. **2FA** for Admins: not implemented (platform-wide).
3. **Audit integrity:** append-only is enforced by MySQL triggers + least-privilege grants; there is no hash chain / off-box copy, and `TRUNCATE`/`DROP` are blocked only by grants. A DBA can still alter history.
4. **Backups:** no scripted backup/restore. A manual dump/restore drill on 2026-10-29 restored every row count and recreated all four append-only triggers (see the review report); encrypted Page tokens are unreadable without the original `APP_KEY`.
5. **Static analysis:** Larastan ran in CI (150 findings at level 5, non-blocking); needs triage/baseline before it can gate.
6. **`ads_management` / permission set** to be settled on a real test Page.
