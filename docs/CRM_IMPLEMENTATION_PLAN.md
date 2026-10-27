# Shvet Technologies CRM — Architecture & Implementation Plan

Status: **Phase 0 approved with amendments (multi-tenancy added, §3A). Phase 1a (foundation, auth, tenancy, RBAC, isolation tests) implemented and security-reviewed (see `docs/PHASE_1A_SECURITY_REVIEW.md`) — awaiting approval. Nothing committed.**
Last updated: 2026-10-27 (rev. 2 — multi-tenancy)

---

## 1. Workspace findings (Phase 0 inspection)

| Item | Finding |
|---|---|
| Repository | `shvet/shvet_tech_crm`, branch `claude/vibrant-lovelace-97xj4m`, **no commits, no files**. Greenfield project. |
| OS | Ubuntu 24.04 LTS (development container; ephemeral) |
| PHP | 8.3.6 CLI with `pdo_mysql`, `mysqli`, `redis`, `intl`, `mbstring`, `openssl`, `sodium`, `gd`, `zip`, `pcntl`, `fileinfo`, `curl`. `bcmath` is not installed — not required (money stored as DECIMAL; PHP-side arithmetic via brick/math if needed). |
| Composer | 2.8.12 |
| Node / npm | Node 22.22.0, npm 10.9.4 |
| MySQL | **Not installed** in the container (Ubuntu apt mirror reachable, so it can be installed for local tests). |
| Redis | 7.0.15 server + CLI installed |
| PostgreSQL | psql 16 client present (not used) |
| Web server | None installed (not needed for dev; `php artisan serve` + Vite) |
| Network | Packagist, npm registry and Ubuntu apt reachable. **`graph.facebook.com` is not reachable** from this sandbox — real Meta/WhatsApp API calls can only be tested from a staging/production host. |
| Laravel | Latest 12.x is **12.70.1**; Laravel **13.x** is current (13.38.0, requires PHP ≥ 8.3). See decision D1. |

### Constraints identified
1. No Meta/WhatsApp credentials exist yet; all integration work in Phases 2–3 will be built against recorded fixtures and mocked HTTP, then verified on a reachable staging host.
2. The dev container is ephemeral; nothing persists unless committed and pushed (which requires the owner's approval per the engineering rules).
3. Production hosting is assumed to be a single Linux VPS (see §9).

---

## 2. Decisions needing owner input

| # | Decision | Recommendation | Default if not answered |
|---|---|---|---|
| D1 (**resolved: Laravel 13 if compatibility verified**) | Laravel 12 vs Laravel 13 | **Laravel 13.** Laravel 12 reached end of bug-fix support around Aug 2026 and receives security fixes only until ~Feb 2027. A new production system started today should begin on 13 (same PHP 8.3 we already have; same architecture). | Laravel 13 |
| D2 | Frontend delivery | **Inertia.js 2 + React + TypeScript + Vite + Tailwind + shadcn/ui** (Laravel's official React starter kit). Keeps session/CSRF auth, no separate token API, server-side routing & authorization. | Inertia + React |
| D3 | Meta credential model | **Business Manager System User token** (non-expiring, scoped to the Page, Ad Account and WABA) entered by an Admin, validated with `debug_token`. "Facebook Login for Business" OAuth reconnect added as an option. | System User token |
| D4 | WhatsApp message body storage | Encrypt message bodies at rest (Laravel `encrypted` cast). Trade-off: message text is not full-text searchable; inbox search uses name/phone/lead. | Encrypt |
| D5 | Commit/push of Phase 0 docs | Owner approval required by rule 15. | Leave uncommitted |

---

## 3. Proposed architecture

### 3.1 Style
A **modular monolith** in a single Laravel application. One MySQL database, one Redis instance, one queue-worker pool, one scheduler. No microservices, containers-orchestration or message brokers beyond Redis.

```
                    ┌──────────────────────────── VPS ─────────────────────────────┐
 Browser ──HTTPS──▶ │ Nginx ─▶ PHP-FPM (Laravel 13)                                 │
 (Inertia/React)    │            ├─ Web routes (session auth, CSRF, policies)      │
                    │            ├─ Webhook routes (/webhooks/meta, /webhooks/whatsapp)
 Meta ──HTTPS──────▶│            │     verify signature → store event → 200 fast   │
 (webhooks)         │            └─ Dispatch jobs ─▶ Redis queue                    │
                    │ Supervisor: queue workers (webhooks, messages, sync, default) │
                    │ Cron: schedule:run (reminders, sync, retention pruning)        │
                    │ MySQL 8 (data)    Redis 7 (queue, cache, sessions, locks)      │
                    │ Private storage (WhatsApp media, CSV imports) — not web-served │
                    └───────────────────────────────────────────────────────────────┘
                                   │ outbound HTTPS (Graph API, Cloud API)
                                   ▼
                          graph.facebook.com
```

### 3.2 Backend module layout (Laravel conventions, grouped by domain)

```
app/
  Models/                      Eloquent models (Lead, PipelineStage, FollowUpTask, WhatsAppMessage, ...)
  Http/
    Controllers/               Thin controllers, one folder per area (Leads, Tasks, Inbox, Admin, Webhooks)
    Requests/                  FormRequest validation per action
    Resources/                 API/Inertia resources (explicit field allow-lists; never toArray() a model)
    Middleware/                EnsureUserIsActive, security headers, webhook raw-body capture
  Policies/                    LeadPolicy, TaskPolicy, ConversationPolicy, IntegrationPolicy, ...
  Actions/                     Single-purpose domain operations (CreateLead, AssignLead, ChangeStage,
                               CompleteTask, ImportLeads, LinkConversationToLead, SendWhatsAppMessage)
  Services/
    Phone/PhoneNormalizer      libphonenumber, default region IN, E.164 output
    Leads/DuplicateDetector    phone/email candidate matching (never auto-merges)
    Leads/LeadVisibility       central query scope for record-level access
    Meta/GraphClient           HTTP client: versioned base URL, timeouts, retries, rate-limit headers
    Meta/LeadAdsService        page/form discovery, page subscription, lead retrieval
    Meta/MarketingService      campaign + insights sync (optional module)
    Meta/WebhookSignature      X-Hub-Signature-256 verification
    WhatsApp/CloudApiClient    send text/template/media, media upload/download, templates sync
    WhatsApp/MessagingPolicy   24h window, template rules, opt-out checks
    Reporting/*                Dashboard + report query objects (pure SQL aggregates)
  Jobs/                        ProcessMetaLeadgenEvent, ProcessWhatsAppEvent, SendWhatsAppMessage,
                               SyncMetaCampaignInsights, SendTaskReminder, PruneRetainedData
  Support/Logging/RedactingProcessor  Monolog processor that strips tokens/PII
  Notifications/               Database (in-app) notifications; mail optional
config/crm.php                 Retention days, default currency (INR), timezone (Asia/Kolkata), feature flags
config/services.php            meta.* / whatsapp.* — values from env only
```

Rules: external API calls live only in `Services/*` and jobs (never in controllers); every write that spans tables runs in a DB transaction; every job that touches the outside world is idempotent.

### 3.3 Frontend
- Inertia pages under `resources/js/pages/*`, shared layout with sidebar: Dashboard, Leads, Pipeline (kanban), WhatsApp Inbox, Follow-ups, Campaigns, Reports, Team, Integrations, Audit Log.
- Reusable components (`DataTable`, `FilterBar`, `StatusBadge`, `UserPicker`, `ConfirmButton`, `EmptyState`, `ErrorState`, `Timeline`) built on shadcn/ui primitives (accessible Radix base).
- Server-side pagination/sorting/filtering via query string (shareable URLs). Inline edits use Inertia partial reloads.
- Inbox uses short polling (e.g., every 10 s while focused) in Phase 3; WebSockets (Laravel Reverb) only if polling proves insufficient.
- Display timezone Asia/Kolkata, currency INR (₹, Indian digit grouping via `Intl.NumberFormat('en-IN')`). All timestamps stored in UTC.

### 3.4 Authentication & authorization
- Session auth (Laravel starter kit / Fortify): login, logout, password reset, profile, optional TOTP 2FA for Admins (recommended). **Public registration disabled**; Admins create users and send invite/reset links.
- Login throttling (5 attempts/min per email+IP), session regeneration on login, `HttpOnly` + `Secure` + `SameSite=Lax` cookies, CSRF on all state-changing web routes, inactive users are logged out by middleware.
- Roles via `spatie/laravel-permission` (Admin, Sales Manager, Sales Agent) with granular permissions (e.g., `leads.export`, `leads.import`, `leads.bulk_update`, `integrations.manage`, `users.manage`, `audit.view`).
- **Record-level access** enforced by Policies + a single `LeadVisibility` scope used by every lead, task, conversation and report query:
  - Admin: all records.
  - Sales Manager: records owned by users in their team(s) + unassigned leads.
  - Sales Agent: records they own / are assigned to; unassigned leads only if the "agents can claim unassigned leads" setting is on (default off).
- Routes use implicit model binding + `authorize()`; IDs from another user's scope return 404/403 (IDOR protection). Bulk actions re-check every ID server-side.

### 3.5 Integrations (detail in `INTEGRATION_REQUIREMENTS.md`)
Webhook pipeline (identical shape for Meta Lead Ads and WhatsApp):

1. `GET` verification: compare `hub.verify_token` (constant-time) and echo `hub.challenge`.
2. `POST`: read **raw** body, verify `X-Hub-Signature-256` = `sha256=` HMAC-SHA256(raw body, app secret) with `hash_equals`. Invalid → 401/403, sanitized log line, no processing.
3. Split the payload into individual changes; compute a deterministic dedupe key per change (`leadgen:{leadgen_id}`, `wa_msg:{wamid}`, `wa_status:{wamid}:{status}`); `INSERT ... ON DUPLICATE KEY` into `webhook_events` (unique key); return `200` immediately.
4. Queue job per new event. The job re-fetches authoritative data from the Graph API (a lead ID alone is never trusted), maps it, writes in a transaction, and marks the event processed.
5. Bounded retries with exponential backoff (e.g., 5 tries: 10 s, 1 min, 5 min, 30 min, 2 h); permanent 4xx errors fail fast; failures visible in Integrations → Recent errors with a "Retry" action.

### 3.6 Lead identity, duplicates & attribution
- Phones normalized to E.164 (default region IN); emails lower-cased and trimmed.
- On any create (manual, import, Meta, WhatsApp), `DuplicateDetector` finds candidates by phone/email. Manual entry shows a warning and asks to confirm; automated ingestion creates the lead **and** a `lead_duplicate_candidates` row for human review. **No automatic merges, no overwrite of existing data.** Merge is a later, explicit, audited action.
- First-touch attribution (source, Meta page/form/campaign/ad IDs, or WhatsApp Click-to-WhatsApp referral) is written once and never overwritten. Later touches append to `lead_attributions`. Unknown attribution is stored as unknown, not guessed.

### 3.7 Logging, privacy & retention
- Monolog processor redacts: `access_token`, `Authorization`, `appsecret_proof`, app secret, verify token, phone numbers/emails (masked), and message bodies. Raw webhook payloads are **not** logged.
- Raw webhook payloads are stored encrypted in `webhook_events.payload` only for the retention window (default 14 days, configurable), then purged by a scheduled job; sanitized metadata (IDs, type, timing, outcome) is retained longer (default 180 days).
- Default retention (configurable in `config/crm.php`): webhook payloads 14 d; message status events 90 d; CSV import files 7 d; WhatsApp media files 180 d; audit logs 2 y; leads until erasure request or 3 y of inactivity (flag for review, not auto-delete).
- Data-subject handling (India DPDP Act 2023 and the DPDP Rules): export of a lead's data, erasure/anonymisation workflow (Admin only, audited), consent records with source and timestamp, opt-out honoured across channels. Legal applicability and timelines to be confirmed by Shvet's counsel.

### 3.8 Background processing
Queues on Redis: `webhooks` (high), `messages` (high), `default`, `sync` (low). Supervisor runs workers; `failed_jobs` table plus an Admin view. Laravel Horizon is optional (adds a dashboard; recommended once Redis is confirmed on the VPS). Scheduler (cron every minute): task reminders, overdue marking, Meta insights sync, template sync, token-health check, retention pruning.

Reminder de-duplication: a reminder is sent only by an atomic `UPDATE follow_up_tasks SET reminder_sent_at = NOW() WHERE id = ? AND reminder_sent_at IS NULL`; retries that lose the race do nothing.

---

## 3A. Multi-tenancy (added after Phase 0 review)

Shvet CRM is built **multi-tenant from the start** so it can later be offered as SaaS. The first tenant is Shvet Technologies. Billing is out of scope.

### 3A.1 Model: shared database, shared schema, `tenant_id` discriminator
One MySQL database; every tenant-owned table has a non-null `tenant_id` FK to `tenants`. Chosen over database-per-tenant because it keeps a small CRM operationally simple (one migration run, one backup, one queue pool). Defense in depth (below) compensates for the weaker isolation.

### 3A.2 Global vs tenant-owned tables
| Scope | Tables |
|---|---|
| **Global (platform)** | `tenants`, `users` (global identity, globally unique email), `tenant_memberships` (the link), `permissions`, `password_reset_tokens`, `sessions`, `migrations`, `failed_jobs`/`jobs` (payload carries tenant_id), `webhook_events` (tenant_id nullable until routed), `cache` |
| **Tenant-owned (`tenant_id NOT NULL`)** | `roles` (per-tenant; spatie "teams" mode with `tenant_id` as team key), `model_has_roles`, `model_has_permissions`, `teams`, `settings`, `audit_logs` (nullable only for platform events), `lead_sources`, `pipeline_stages`, `products`, `leads`, `lead_*`, `notes`, `follow_up_tasks`, `assignment_history`, `communication_consents`, `notifications` (via tenant-scoped notifiable), `lead_imports*`, `integrations`, `meta_pages`, `meta_lead_forms`, `meta_lead_records`, `campaigns`, `campaign_metrics`, `whatsapp_*`, `message_status_events` |

### 3A.3 Users, memberships and roles
- A **user is a global identity** (one login, one email, one password). Access to a tenant exists only through an **active `tenant_memberships` row** (`tenant_id`, `user_id`, `status` invited|active|suspended|removed, `team_id`, `invited_by`, `joined_at`; UQ(tenant_id, user_id)). A user may belong to several tenants.
- **Roles are tenant-scoped**: Admin, Sales Manager, Sales Agent are created per tenant at provisioning; role assignments carry `tenant_id`. Permission names are global constants. The same user can be Admin in tenant A and Agent in tenant B.
- Guards: the last active Admin of a tenant cannot be removed, suspended or demoted.
- Platform administration (provisioning, suspending tenants) is an **Artisan command / future platform console, not a tenant role**. Platform operators get no implicit read access to tenant data; any support access must be explicit, time-boxed and audited (future work).
- `users.is_active=false` disables login everywhere; membership `suspended`/`removed` disables one tenant.

### 3A.4 Current-tenant resolution
- The active tenant is stored in the session (`current_tenant_id`) and **re-validated on every request** by `ResolveTenant` middleware against an active membership of the authenticated user and `tenants.status = active`. Users with one membership are auto-selected; with several they pick from a tenant switcher. Switching regenerates nothing sensitive but is audit-logged.
- Tenant identity is **never accepted from request input** (no `tenant_id` form fields, headers or query params are trusted). Route-model-bound records are resolved through tenant-scoped queries so another tenant's ID yields 404.
- Spatie's team id is set from the resolved tenant for the request.

### 3A.5 Record scoping (fail closed)
- `BelongsToTenant` trait on every tenant-owned model: a global `TenantScope` adds `where tenant_id = <current>`; `creating` hook fills `tenant_id` from context and **refuses a mismatching explicit value**. If no tenant context exists the scope **throws `TenantContextMissing`** rather than returning unscoped data. Cross-tenant access requires an explicit, greppable `Tenancy::withoutScope()` / `Tenancy::runAs($tenant, fn)` and is limited to provisioning, routed webhooks, and the scheduler fan-out.
- Raw/`DB::table` queries and reports must use `Tenancy::id()`; a Pest architecture test fails if a tenant-owned model lacks the trait.
- Record-level rules (Admin all / Manager team / Agent own) apply **inside** the tenant boundary (`LeadVisibility`).

### 3A.6 Database defense in depth
- Every child row carries `tenant_id` and **composite foreign keys** `(tenant_id, parent_id) → parent(tenant_id, id)` (parents expose `UNIQUE(tenant_id, id)`), so the database rejects a task or message pointing at another tenant's lead even if application code is wrong.
- Business uniqueness is **per tenant**: UQ(tenant_id, slug/key/…), IDX(tenant_id, phone_e164), IDX(tenant_id, email), etc. All list indexes lead with `tenant_id`.
- Provider assets are **globally unique** so one external asset can belong to only one tenant: `meta_pages.page_id`, WhatsApp `phone_number_id`/`waba_id`, `meta_lead_records.leadgen_id`, `whatsapp_messages.wamid`. This blocks a tenant from claiming another tenant's Page/number.

### 3A.7 Integration ownership and webhook routing (Phases 2–3 design, enforced now)
- `integrations` rows are tenant-owned: `tenant_id`, `provider`, `auth_mode` (`manual_system_user` | `oauth`), encrypted `credentials`, `connected_by`. UQ(tenant_id, provider). Credentials are decryptable only inside tenant context; Admin-only (`integrations.manage`); never serialized to the frontend.
- **Manual mode** (initial testing): the tenant admin enters its own System User token (and optionally its own Meta app ID/secret). Webhook URL contains the integration's unguessable public ID: `/webhooks/meta/{integration:public_id}` → resolves tenant + per-integration app secret and verify token.
- **OAuth/SaaS mode** (future): one platform Meta app (Tech Provider) with Facebook Login for Business / WhatsApp Embedded Signup per tenant. Single global webhook URL verified with the platform secret; the event is **routed to a tenant solely by the verified asset ID** (`page_id`, `phone_number_id`) looked up in the globally-unique asset tables. Unknown asset → event stored as `ignored`, no tenant data touched.
- The credential/client layer is an interface (`MetaCredentialProvider`) resolved per tenant, so switching a tenant from manual to OAuth changes the provider, not the callers.
- `webhook_events.tenant_id` is set at routing time; processing jobs refuse to run if the integration's tenant differs from the event's.

### 3A.8 Tenant-aware queues, scheduler, cache and storage
- Every job that touches tenant data implements `TenantAware` and stores `tenant_id` in its payload (never loads "current" state from the worker). A queue **job middleware** (`SetTenantContext`) boots the tenant (must exist and be active, else the job is released/failed), runs the job, and clears context + spatie team id in `finally`. `Queue::before/after/failing` listeners clear context so a long-running worker cannot leak tenant state between jobs. Dispatching a `TenantAware` job without a tenant context throws.
- Retried jobs keep the same `tenant_id`; de-dupe keys include `tenant_id`.
- Scheduled work fans out: a scheduler command iterates active tenants and dispatches one tenant-aware job per tenant (failures isolated).
- Cache keys, rate-limiter keys and file paths are tenant-prefixed (`t:{id}:…`, `tenants/{public_id}/…`) on private disks; downloads authorize tenant ownership.
- Logs include `tenant_id` as context (never tokens/PII).

### 3A.9 Tenant provisioning
`ProvisionTenant` action (single DB transaction): create tenant (ULID `public_id`, slug), seed per-tenant roles, default lead sources, pipeline stages (New → Contacted → Qualified → Proposal Sent → Negotiation → Won/Lost), default settings; create-or-attach the owner user and an active Admin membership; write an audit log. Entry points: `php artisan tenants:provision` (now) and a future platform console. Public self-registration stays disabled until billing exists; tenant admins add users by invitation (membership with `status=invited`).

### 3A.10 Required cross-tenant tests (Pest)
1. Tenant-owned model query returns only current-tenant rows; no context → exception.
2. Creating a model fills `tenant_id`; forging another tenant's id is refused.
3. User with membership only in tenant A gets 403/404 on every tenant-B route and record (IDOR matrix, per resource added).
4. Switching to a tenant without an active membership is rejected; suspended membership / suspended tenant / deactivated user lose access immediately.
5. Same user, two tenants, different roles → permissions differ per active tenant.
6. Roles, role assignments and settings do not leak across tenants; assigning a tenant-A role to a tenant-B membership is rejected.
7. Composite FKs reject cross-tenant parent references at the DB level.
8. Per-tenant uniqueness works (same slug/email-in-tenant rules) and global provider-asset uniqueness blocks reuse across tenants.
9. Queue jobs: context set and cleared, job for suspended/missing tenant does not run, context never leaks between consecutive jobs on the same worker, retries keep tenant.
10. Last-admin protection; invitation cannot grant roles above inviter's authority.
11. Architecture test: every tenant-owned model uses `BelongsToTenant`.
12. Audit logs, exports and reports contain only current-tenant data.

---

## 4. Initial database schema

MySQL 8.0+ (InnoDB, `utf8mb4_unicode_ci`). All tables have `id BIGINT UNSIGNED` PK and `created_at/updated_at` unless noted. Provider IDs are stored as `VARCHAR` (Meta IDs exceed 2^53 and must never be cast to float/int in JS). Money is `DECIMAL(14,2)` + `currency CHAR(3)`.

### 4.1 Identity & access
> **Tenancy rule for every table below:** all tables except those marked *Global* (and the framework tables) carry `tenant_id NOT NULL` (FK → `tenants`), lead all indexes with it, scope uniques per tenant, and use composite `(tenant_id, parent_id)` foreign keys. See §3A. Where a row below lists `UQ(x)`, read `UQ(tenant_id, x)` unless it is a provider asset ID (globally unique by design).

| Table | Key columns | Constraints / indexes |
|---|---|---|
| `tenants` | public_id (ULID), name, slug, status (active, suspended), timezone, currency, settings (JSON) | UQ(slug), UQ(public_id). **Global.** |
| `tenant_memberships` | tenant_id, user_id, team_id, status (invited, active, suspended, removed), invited_by, joined_at | UQ(tenant_id, user_id); IDX(user_id, status). **Global link table.** |
| `users` (global identity; tenant access only via memberships) | name, email, password, phone, is_active, last_login_at, two_factor_* | UQ(email). Users are deactivated, not deleted. No `tenant_id`/`team_id` on the user. |
| `teams` | name, manager_id→users | Simple one-team-per-user model |
| `roles`, `permissions`, `model_has_roles`, `model_has_permissions`, `role_has_permissions` | spatie/laravel-permission | — |
| `password_reset_tokens`, `sessions` | Laravel standard (sessions in Redis in prod) | — |
| `settings` | key, value (JSON) | UQ(key). e.g. `agents_can_claim_unassigned`, retention overrides |
| `audit_logs` | user_id, action, auditable_type/id, changes (JSON, redacted), ip, user_agent, created_at | IDX(auditable_type, auditable_id), IDX(user_id, created_at), IDX(action, created_at). Append-only. |

### 4.2 Leads & pipeline
| Table | Key columns | Constraints / indexes |
|---|---|---|
| `lead_sources` | key (facebook_ads, instagram_ads, whatsapp, website, manual, referral, other), name, is_system, is_active | UQ(key) |
| `pipeline_stages` | key, name, position, outcome ENUM(open, won, lost), color, is_active | UQ(key). Stages are archived (`is_active=0`), never hard-deleted while referenced; reports group by stable `key`/outcome. |
| `products` | name, is_active | "Interested product/service" (configurable list) |
| `leads` | public_id (ULID), full_name, phone_e164, phone_raw, email, company_name, product_id, interest_notes, source_id (first-touch, immutable), pipeline_stage_id, priority ENUM(low, medium, high, urgent), owner_id→users, estimated_value, won_value, currency, lost_reason, next_follow_up_at, first_contacted_at, last_contacted_at, won_at, lost_at, created_by, deleted_at | IDX(phone_e164), IDX(email), IDX(owner_id, pipeline_stage_id), IDX(source_id, created_at), IDX(next_follow_up_at), IDX(created_at), FULLTEXT(full_name, company_name). Phone/email deliberately **not** unique (duplicates flagged, not blocked). |
| `lead_duplicate_candidates` | lead_id, candidate_lead_id, match_type ENUM(phone, email), status ENUM(open, dismissed, merged), resolved_by, resolved_at | UQ(lead_id, candidate_lead_id, match_type) |
| `lead_stage_history` | lead_id, from_stage_id, to_stage_id, changed_by, changed_at | IDX(lead_id, changed_at), IDX(to_stage_id, changed_at) — powers conversion and funnel reports |
| `assignment_history` | assignable_type (lead/conversation), assignable_id, from_user_id, to_user_id, assigned_by, reason, created_at | IDX(assignable_type, assignable_id, created_at). Replaces separate lead/conversation assignment tables. |
| `lead_attributions` | lead_id, is_first_touch, source_id, channel_ref (form/referral type), meta_page_id, meta_form_id, meta_ad_id, meta_adset_id, meta_campaign_id, campaign_id→campaigns, ctwa_clid, occurred_at, first_touch_lead_id (generated: lead_id when is_first_touch) | UQ(first_touch_lead_id) guarantees exactly one first touch; IDX(campaign_id) |
| `lead_activities` | lead_id, user_id, type (note, call_logged, stage_changed, assigned, task_created, task_completed, wa_inbound, wa_outbound, meta_ingested, imported, consent_changed, …), subject_type/id, properties (JSON, sanitized), occurred_at | IDX(lead_id, occurred_at) — timeline |
| `notes` | lead_id, user_id, body, deleted_at | IDX(lead_id, created_at) |
| `follow_up_tasks` | lead_id (nullable), assigned_to, created_by, type ENUM(call, whatsapp, meeting, email, other), title, description, priority, due_at, status ENUM(pending, completed, cancelled), completed_at, completed_by, outcome, reminder_sent_at | IDX(assigned_to, status, due_at), IDX(lead_id, due_at), IDX(status, due_at, reminder_sent_at) |
| `communication_consents` | lead_id, whatsapp_contact_id, channel ENUM(whatsapp, email, sms, call), purpose ENUM(service, marketing), status ENUM(granted, revoked), source (meta_form, wa_inbound, website, manual, import, opt_out_keyword), evidence, recorded_by, recorded_at | Append-only; IDX(lead_id, channel, purpose, recorded_at). Current state = latest row. |
| `notifications` | Laravel database notifications (UUID PK) | IDX(notifiable_type, notifiable_id, read_at) |
| `lead_imports` | user_id, file_path (private), status (uploaded, previewed, committed, failed, expired), counts, column_map (JSON), committed_at | — |
| `lead_import_rows` | lead_import_id, row_number, data (encrypted JSON), status (valid, invalid, duplicate, skipped, imported), errors (JSON), duplicate_lead_id, decision, lead_id | IDX(lead_import_id, status). Purged with the file. |

### 4.3 Integrations & webhooks
| Table | Key columns | Constraints / indexes |
|---|---|---|
| `integrations` | provider ENUM(meta_lead_ads, meta_marketing, whatsapp), status (disconnected, connected, degraded, token_invalid), config (JSON, non-secret IDs, Graph version), credentials (TEXT, **encrypted cast**), scopes (JSON), token_expires_at, last_verified_at, last_sync_at, last_webhook_at, last_error_at, last_error (sanitized), connected_by | UQ(provider) |
| `meta_pages` | integration_id, page_id, name, page_token (encrypted), leadgen_subscribed_at, is_active | UQ(page_id) |
| `meta_lead_forms` | meta_page_id, form_id, name, status, field_map (JSON: Meta question key → CRM field), synced_at | UQ(form_id) |
| `webhook_events` | provider, event_type, dedupe_key, signature_valid, status ENUM(pending, processing, processed, failed, ignored), attempts, payload (encrypted, nullable), payload_purged_at, metadata (JSON: sanitized IDs), last_error, received_at, processed_at | UQ(provider, dedupe_key); IDX(status, received_at) |
| `meta_lead_records` | leadgen_id, page_id, form_id, ad_id, adset_id, campaign_id (provider IDs), ad_name, campaign_name, lead_created_time, is_organic, lead_id→leads, status (pending, fetched, lead_created, failed), error, field_data (encrypted, purged after retention) | UQ(leadgen_id) — the core idempotency guarantee |
| `campaigns` | provider, ad_account_id, external_id, name, objective, status, platform hint | UQ(provider, external_id) |
| `campaign_metrics` | campaign_id, metric_date (ad-account timezone), spend, currency, impressions, reach, clicks, meta_reported_leads, fetched_at | UQ(campaign_id, metric_date). Daily grain = clear reporting periods; re-fetches upsert (Meta revises recent days). |

### 4.4 WhatsApp
| Table | Key columns | Constraints / indexes |
|---|---|---|
| `whatsapp_contacts` | wa_id (provider), phone_e164, profile_name, lead_id (confirmed link only), link_status ENUM(unlinked, linked, ambiguous) | UQ(wa_id); IDX(phone_e164) |
| `whatsapp_conversations` | whatsapp_contact_id, business_phone_number_id, lead_id, assigned_to, status ENUM(open, pending, closed), last_message_at, last_inbound_at (drives 24-h window), unread_count | UQ(whatsapp_contact_id, business_phone_number_id); IDX(assigned_to, status, last_message_at) |
| `whatsapp_messages` | conversation_id, direction ENUM(in, out), wamid, client_ref (UUID idempotency key for outgoing), kind (text, template, image, document, audio, video, sticker, location, interactive, reaction, unsupported), body (encrypted), template_name, template_language, template_params (JSON), media_id, media_mime, media_size, media_path (private disk), status (queued, sending, sent, delivered, read, failed, received), status_rank, error_code, error_title, sent_by, provider_timestamp, referral (JSON — Click-to-WhatsApp ad data) | UQ(wamid) (nullable), UQ(client_ref); IDX(conversation_id, provider_timestamp) |
| `message_status_events` | whatsapp_message_id (nullable until reconciled), wamid, status, provider_timestamp, error_code, error_title | UQ(wamid, status). Status only moves forward by `status_rank` (sent < delivered < read; `failed` terminal); out-of-order events are stored and reconciled when the message row appears. |
| `whatsapp_templates` | name, language, category, status, components (JSON), synced_at | UQ(name, language) |

### 4.5 Framework tables
`jobs` (unused if Redis), `job_batches`, `failed_jobs`, `cache` (unused if Redis), `migrations`.

---

## 5. Phase plan & acceptance criteria

### Phase 0 — Repository & architecture (this phase)
- [x] Inspect environment and repository.
- [x] Architecture & schema (this document).
- [x] Integration prerequisites (`docs/INTEGRATION_REQUIREMENTS.md`).
- [ ] Owner approval of decisions D1–D5.

### Phase 1 — Working CRM foundation (no external credentials needed)
Checklist:
1. Scaffold Laravel (version per D1) with the React/Inertia/TypeScript starter kit; remove public registration; Pest for backend tests; Vitest + React Testing Library for components; ESLint/Prettier, Laravel Pint, Larastan (level 6+).
2. `.env.example` with all keys (no secrets); `.gitignore` covers `.env`, storage, uploads.
3. Install MySQL 8 locally in the container for tests (tests run on MySQL, not SQLite, so FULLTEXT/JSON/unique behaviour matches production); Redis for queue/cache/session.
4. Security baseline: security headers middleware (CSP, HSTS in prod, X-Frame-Options, Referrer-Policy), login throttling, inactive-user middleware, redacting log processor, rate limiting on all routes.
5. Roles & permissions (spatie), teams, `LeadVisibility` scope, policies for every model; Admin user management (create, deactivate, reset password, role/team) with audit logging.
6. Migrations, factories and seeders for: users/teams/roles, lead sources, pipeline stages, products, leads, duplicate candidates, stage history, assignment history, attributions (manual/website/referral only), activities, notes, follow-up tasks, consents, notifications, settings, audit logs, imports.
7. `PhoneNormalizer` (giggsey/libphonenumber-for-php) + `DuplicateDetector`.
8. Lead CRUD: list (search name/phone/email/company, filters, sortable columns, pagination), create/edit with duplicate warning, detail page with timeline, notes, stage change, assignment (single + bulk), permitted bulk status update, soft delete (Admin/Manager).
9. Pipeline: kanban view by stage with drag/drop stage change (server-validated), Admin stage configuration (rename, reorder, archive, add) without breaking history.
10. Follow-ups: create/assign/complete with outcome; Today/Upcoming/Overdue views; lead-level history; scheduler job for reminders with atomic de-duplication; in-app notifications with unread badge.
11. Dashboard: totals, new today/this month, by source, awaiting first contact, due today, overdue, qualified, won/lost, conversion rate, revenue — all real queries, filtered by date range, source, agent (campaign filter shows "no Meta data yet" until Phase 2), respecting record visibility.
12. CSV export (permission-checked, scoped to visible leads, audited, formula-injection-safe) — import preview/commit moves here too if time allows, otherwise Phase 4 as planned.
13. Tests: auth & throttling, role & record-level access (incl. IDOR attempts), lead CRUD & validation, phone normalization, duplicate detection, assignment & bulk assignment, stage transitions & history, follow-up scheduling & reminder idempotency, dashboard metric accuracy, pagination/filtering, export permission, credential encryption cast, log redaction.
14. `README.md` setup instructions; update this plan.

Acceptance criteria: fresh clone → documented setup → seeded Admin can log in, create users of all three roles, and those users can create, assign, update, follow up on leads and see accurate dashboard numbers; agents cannot read or modify other agents' leads via UI or direct URLs; `php artisan test`, `npm run test`, `npm run build`, Pint and Larastan all pass.

### Phase 2 — Meta Lead Ads
Integration settings page; System User token entry + `debug_token` validation; Page & form discovery; page `subscribed_apps` (leadgen); webhook verify/signature; event store + queue job; Graph lead retrieval; field mapping per form; lead creation with attribution and duplicate flagging; error dashboard & retry; fixture-based tests incl. duplicate deliveries and invalid signatures. Real test lead via Meta's Lead Ads Testing Tool on staging.

### Phase 3 — WhatsApp Cloud API
Settings + connection test (read-only calls); webhook for `messages` (inbound + statuses); shared inbox; free-form send inside 24-h window; template send (synced approved templates, parameter validation); media send/receive with MIME/size limits; status tracking with out-of-order handling; lead linking (auto only on single unambiguous verified match, otherwise agent picks); consent/opt-out (incl. STOP keyword); tests with fixtures. Real test via an authorized test number on staging.

### Phase 4 — Sales operations & reporting
Unified workspace (new Meta leads, unanswered conversations, unassigned, due today); campaign reporting (Meta-reported vs CRM-calculated columns, clearly labelled); conversion & team performance reports; CSV import with preview/validation/duplicate decisions; duplicate review & explicit merge.

### Phase 5 — Production readiness
Full test suite; authorization review; secrets/log review; webhook idempotency & queue-recovery drills; backup/restore runbook; deployment, scheduler, worker, monitoring docs; go/no-go checklist.

---

## 5A. Phase 1a — implementation status (foundation slice)

Delivered in this slice (see `README.md` for setup and commands):

| Area | State |
|---|---|
| Laravel 13.38 on PHP 8.3 (compatibility verified: `laravel/framework ^13.17` requires PHP ≥ 8.3) | Done |
| Inertia 3 + React 19 + TypeScript + Vite 8 + Tailwind 4 UI shell | Done: login, forgot/reset password, workspace picker, dashboard shell, team management, profile |
| Auth: login/logout, password reset, profile, throttling, session regeneration, `AuthenticateSession`, no public registration | Done |
| Tenancy core: `tenants`, `tenant_memberships`, `BelongsToTenant` + fail-closed `TenantScope`, `ResolveTenant` (re-validated every request), `TenancyManager::runAs/withoutScope/eachActiveTenant` | Done |
| Composite-FK isolation in the DB (leads → sources/stages/owner membership; role assignments → same-tenant role + member) | Done |
| Tenant-scoped roles/permissions (spatie teams mode keyed by `tenant_id`), Admin / Sales Manager / Sales Agent matrix | Done |
| Membership lifecycle: invite → accept (existing user) / set-password (new user), role change, suspend/reinstate/remove, last-admin protection | Done |
| Tenant provisioning: `ProvisionTenant` action + `php artisan tenants:provision` | Done |
| Tenant-aware queue support (`TenantAware`, `QueuesForTenant`, `SetTenantContext`, worker context reset) and scheduler fan-out | Done (tested with a probe job; no production jobs exist yet) |
| Audit log (tenant + platform events), log redaction (processor + tap), security headers, trusted-proxy config | Done |
| CI workflow (`.github/workflows/ci.yml`) | Written, **not yet run on GitHub** |
| Security review fixes (audit immutability, invitation expiry/consent/limits, named rate limiters, deactivated-user lockout, host enforcement, production preflight, history encryption) | Done — see `docs/PHASE_1A_SECURITY_REVIEW.md` |
| Browser smoke suite (`e2e/smoke.cjs`, 27 checks) | Done; manual, not in CI |

Deliberately deferred (not part of this slice): lead CRUD/search/import/export, follow-ups, dashboard metrics, pipeline UI, teams table (`team_id` on memberships — manager "team" scoping needs it), tenant picker polish, 2FA, platform console.

Deviations from the plan, with reasons:
1. **PHPUnit 12 instead of Pest.** Pest's GitHub-hosted dist archives could not be downloaded in the sandbox (composer: "Could not authenticate against github.com"). The brief allows PHPUnit or Pest. Can be added later from an unrestricted machine.
2. **Larastan/PHPStan not installed** for the same reason. Static analysis is therefore not part of the gates yet (Pint, PHPUnit, `tsc`, Vitest, `composer audit`, `npm audit` are). Add `larastan/larastan` in CI or locally.
3. **`teams` table and `memberships.team_id` postponed** to the lead module, where team scoping is first needed.
4. **Minimal `leads` / `lead_sources` / `pipeline_stages` tables** were created now because isolation (scope, composite FKs) needs a real tenant-owned aggregate to test against; remaining lead columns arrive in additive migrations.
5. The integration/WhatsApp tables, credential encryption and message encryption (decisions 2–3) are Phase 2/3 work and are **not** implemented or tested yet.

Known design notes:
- Deleting a tenant row is not supported yet: `leads.owner` composite FK is RESTRICT, so a future purge job must delete tenant data in dependency order.
- Platform-level audit events (`tenant_id IS NULL`, e.g. password reset) are invisible to every tenant by design.

## 6. Testing strategy
- **Backend (Pest)**: feature tests through HTTP for every route (authorization matrix per role), unit tests for normalizer, duplicate detector, signature verifier, messaging policy, status reconciler. External APIs mocked with `Http::fake()` using sanitized fixtures in `tests/Fixtures/meta|whatsapp`. Queue retry behaviour tested with `Queue::fake()` and job `tries/backoff` assertions plus forced-failure tests.
- **Frontend**: Vitest + React Testing Library for components with logic (filters, composer window state, import preview). Optional Playwright smoke test (Chromium is available in the container).
- **Real-API integration tests**: separate, opt-in (`php artisan test --group=live`, skipped unless `LIVE_META_TESTS=1` with staging credentials). Never run in CI by default.
- Only tests that were actually executed are reported as passing.

## 7. Conventions
- PSR-12 via Pint; strict types in new PHP files; Larastan in CI.
- Controllers ≤ thin; validation in FormRequests; authorization in Policies; domain writes in Actions; external I/O in Services/Jobs.
- Inertia props built from Resources with explicit fields (no secrets, no hidden columns).
- Migrations are additive and reversible; no destructive production migrations without approval.
- Conventional commit messages; feature branch per phase.

## 8. Assumptions
1. **Multi-tenant (see §3A).** Shvet Technologies is tenant #1. Per tenant: one WhatsApp number initially (schema supports more), one or a few Facebook Pages and one ad account. Billing/self-service signup is out of scope.
2. Within a tenant, a membership belongs to one team; managers see their team. A user may hold memberships in several tenants.
3. Application timezone UTC; display timezone Asia/Kolkata; currency INR.
4. Instagram lead ads arrive through the linked Facebook Page's `leadgen` webhook; Facebook vs Instagram is determined from the lead's `platform` field where Meta returns it, else marked "Meta Ads (platform unknown)".
5. No automatic promotional messaging; templates are sent only by a user action (bulk campaigns are out of scope).
6. Email reminders are optional and off by default; in-app notifications are primary.
7. Hosting: Ubuntu 22.04/24.04 VPS, ≥ 2 vCPU / 4 GB RAM, with a domain and valid TLS certificate (required by Meta webhooks).

## 9. Target deployment (detailed in Phase 5)
Nginx + PHP-FPM 8.3, MySQL 8.0/8.4, Redis 7, Supervisor (queue workers), cron (`schedule:run`), Let's Encrypt TLS, UFW firewall, daily encrypted DB backups with off-server copy and tested restore, log rotation, uptime monitoring of `/up` and webhook endpoints, failed-job alerting.
