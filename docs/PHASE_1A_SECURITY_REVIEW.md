# Phase 1a — Security & Quality Review

Reviewed: 2026-10-27, on the uncommitted working tree of branch `claude/vibrant-lovelace-97xj4m`.
Method: read the actual source (routes, middleware, policies, models, migrations, jobs/queue support, controllers, config), grep audit of every boundary-crossing call, adversarial tests, and real-browser tests (Chromium via Playwright) against a production-like server (non-`local` env, strict CSP, real MySQL 8).
Scope: Phase 1a only. Meta, WhatsApp and billing are not implemented and were not reviewed.

Severity: **Critical** = cross-tenant data exposure / auth bypass; **High** = serious exploitable weakness; **Medium** = real weakness with limited preconditions or impact; **Low** = hardening / correctness / UX with security relevance.

## 1. Result

No Critical or High defects were found in tenant isolation or authentication. Eleven Medium/Low defects were confirmed and fixed (below). Isolation held under every attack I could construct (tests + mutation checks + browser). Remaining risks are listed in §4; none block a *staging* approval, several are blockers for *production with real customer data* (§5).

## 2. Findings fixed in this review

| # | Sev | Finding | Evidence (file) | Fix | Verified by |
|---|---|---|---|---|---|
| F1 | Medium | **Audit log was mutable**: Eloquent update/delete, query-builder bulk update/delete and raw SQL all succeeded; the `user_id` FK was `SET NULL`, so deleting a user silently rewrote history. | `app/Models/AuditLog.php` (no guards), `2026_10_27_000400…` (`nullOnDelete`) | Model-level `updating/deleting` guards; MySQL `BEFORE UPDATE/DELETE` triggers (`…000500_protect_audit_logs.php`); FK changed to `RESTRICT`. FK cascade on tenant erasure still works (MySQL cascades don't fire triggers). | `tests/Feature/Security/AuditLogIntegrityTest.php` (9 tests incl. raw SQL, bulk, tenant cascade, forged tenant) |
| F2 | Medium | **Password reset auto-activated every pending invitation** of a never-logged-in account — including invitations from *other* tenants — i.e. consent to join a workspace was implied by proving mailbox control. | `NewPasswordController` (removed block) | Removed. Joining always requires the signed-in invitee to accept explicitly. | `MembershipManagementTest::test_new_user_sets_a_password_but_still_has_to_accept…`, `…never_activates_invitations_from_other_workspaces`; browser flow |
| F3 | Medium | **Invitations never expired, were unlimited, and re-inviting was an error** (no renewal). | `AddMember`, `TenantMembership` | `invited_at`/`invitation_expires_at` (default 7 days, `CRM_INVITATION_TTL_DAYS`); accept fails after expiry; picker hides expired; re-invite renews (audited, no duplicate row); per-inviter limit 20/h (`throttle:invitations`); cap 100 pending per tenant. | `tests/Feature/Security/InvitationAbuseTest.php` (10 tests) |
| F4 | Medium | **Deactivated users kept a live session** on routes outside the `tenant` middleware (`/profile`, `/workspaces`, invitation accept/select). | `routes/web.php`, `ResolveTenant` was the only check | New `EnsureUserIsActive` on the whole authenticated group. | `DeactivatedUserTest` (written first, failed, then passed) |
| F5 | Medium | **Unnamed `throttle:N,M` shares one counter per client IP across all routes** — login failures consumed the password-reset budget and a shared office IP could lock everyone out of resets; observed as failures in the browser run. Also no cap on mail-bombing one mailbox. | `routes/web.php` | Named limiters `login`, `password-reset-request` (6/min/IP + 5/h/mailbox), `password-reset`. | `ThrottlingTest` (4 tests); browser suite |
| F6 | Medium | **Insecure production defaults**: `.env.example` hard-coded `SESSION_ENCRYPT=false` (overriding the safe default), session cookie `Secure` unset, default mailer `log` (writes password-reset links to disk). | `.env.example`, `config/session.php`, `config/mail.php` | Defaults are secure when `APP_ENV=production`; `.env.example` fixed; `ProductionSafeguards` + `php artisan crm:preflight` + fail-closed boot guard (debug on, http URL, insecure cookie, log mailer, no host enforcement…). Found because `config:show` under production still printed `encrypt false`. | `ProductionHardeningTest`; manual runs of `crm:preflight` (bare prod → exit 1, correct prod → exit 0) |
| F7 | Medium | **Host-header poisoning of reset/invite links** (Laravel builds URLs from the request host; nothing restricted it). Not exploited before the fix (reasoned from framework behaviour); fix is verified. | `bootstrap/app.php` | `EnforceTrustedHost` middleware (allowed = `APP_URL` host + `CRM_ALLOWED_HOSTS`), on by default in production and required by preflight. | `ProductionHardeningTest::test_reset_links_cannot_be_poisoned…` |
| F8 | Low→Med | **Back button after logout re-rendered the previous user's dashboard** from browser history (server correctly refused, but the page props were cached client-side). Reproduced in Chromium. | `e2e` probe | `Inertia::encryptHistory()` globally + `Inertia::clearHistory()` after session invalidation on logout. | `AuthenticationTest::test_page_history_is_encrypted_and_cleared_on_logout`; browser check "no content visible after logout + back" |
| F9 | Low | **Stale membership state in the last-admin guard** (`ChangeMemberRole` used the route-bound object read before the lock) and role edits were allowed on removed members. | `ChangeMemberRole` | `refresh()` inside the tenant lock; reject removed members. | `CrossTenantAuthorizationTest::test_stale_membership_state_cannot_be_used…` (deterministic stale-object reproduction; true concurrent load not tested) |
| F10 | Low | **Demo seeder blocked only `production`**: a public `staging` host would get known-password accounts. Test suite could also refresh *any* database name. | `DatabaseSeeder`, `phpunit.xml` (carried DB credentials) | Seeder allow-list `local`/`testing`; DB credentials moved to git-ignored `.env.testing`; `TestCase` refuses databases not ending in `_test` (verified against a scratch DB). | `ProductionHardeningTest`; manual guard check |
| F11 | Low | Reset tokens in URLs weren't redacted from logs; login page never showed the post-reset success message; workspace picker hid invitation errors. | `Redactor`, `Login.tsx`, `Workspaces/Index.tsx` | Fixed. | `ProductionHardeningTest`, browser suite |

Also: generated e2e screenshots would have been committed (`.gitignore` fixed); mail `maillog` channel added for local/e2e only (rejected in production by F6).

## 3. Areas audited with no defect found

| Area | What was checked | Result |
|---|---|---|
| Route inventory (18 routes) | Every route's middleware chain; every non-GET route has CSRF (Laravel 13 `PreventRequestForgery`); all tenant routes behind `tenant`; policies on all `team.*` actions. | OK. Note: Laravel 13 accepts the browser-set `Sec-Fetch-Site: same-origin` header *instead of* a token (page JS cannot forge it); cross-site and same-site requests still need a token. Browser test proves header-less / cross-site / same-site forged POSTs get 419. Informational, not a defect. |
| Queries & relationships | Grep of all `withoutScope`/`runAs`/`DB::table`/raw SQL in `app/`: 10 call sites, each reviewed (login/picker/middleware/queue/audit/last-admin); `withoutGlobalScope(s)` banned by test; every model with `tenant_id` must use `BelongsToTenant` (test). | OK |
| Route-model binding | `{membership}` resolved through the tenant scope *after* `ResolveTenant` (priority list); cross-tenant IDs → 404 for PATCH/DELETE. `{tenant}` is unscoped by design and re-checked against an active membership in every action. | OK |
| Workspace switching | Session tenant re-validated every request (membership, tenant, user status); forged session tenant, request-input tenant, header tenant all ignored; per-user role cache cleared on switch; same person shows the *current* tenant's role only. | OK (tests + browser) |
| Database | Composite FKs reject cross-tenant sources, stages, owners, role assignments; global tenant slug unique; per-tenant business keys. | OK (`DatabaseIsolationTest`) |
| Queues/scheduler | Context captured at dispatch, restored in `finally`, cleared by worker loop; suspended/deleted tenant → job failed, body not run; payload tenant wins; retries keep tenant; fan-out isolates failures. | OK (`QueueTenancyTest`; mutation check) |
| Cache / files / exports | **None exist.** A tripwire test now fails if `Cache::`, `Storage::`, downloads, `Mail::to`, `->queue(` are added to `app/` unreviewed. | N/A, guarded |
| Session & auth | Regeneration on login and tenant select; `AuthenticateSession` (password change signs other devices out — browser-proven); HttpOnly + SameSite=Lax cookies; generic errors for unknown/inactive/wrong password; reset never reveals account existence; reset token single-use; weak passwords rejected; profile cannot change email/active/password-hash fields (mass-assignment test). | OK |
| Role enforcement | Admin/Manager/Agent matrix at API level (403 for forbidden writes even with a valid CSRF-origin request); UI hides actions but the server is authoritative. | OK |
| Last-admin protection | Demote/suspend/remove blocked; inactive admins and other tenants' admins don't count; second admin allows step-down. | OK (unit + browser) |
| Invitation abuse (see F2/F3/F5) | Rate limit, pending cap, duplicate invites, expiry, accept by wrong user/other tenant/suspended/removed, e-mail bombing (broker 1/min/address + 5/h/mailbox). | OK after fixes |
| Headers / CSP | Strict CSP active in non-local env: no violations in the full browser run (4 console messages, all deliberate 403/404). | OK |
| Secrets | No credentials in trackable files (scan); `.env*` ignored; demo password only in dev seeder/README/e2e. | OK |

## 4. Remaining risks (not fixed in this review)

| # | Sev | Risk | Notes / next step |
|---|---|---|---|
| R1 | Medium | **No 2FA** for Admins. | Planned; add before production. |
| R2 | Medium | **Audit log is append-only but not tamper-evident**: a DB superuser can drop triggers; no hash chain or off-box copy; no defined retention/prune path (triggers block deletes). | Ship audit rows to an external sink; design retention (partition drop by operator) before Phase 5. |
| R3 | Medium | **Static analysis gate is unverified.** Larastan install failed (GitHub archive auth), and executing a downloaded PHPStan PHAR was blocked by the sandbox policy, so it was *not* run. | Concrete alternative in place: `phpstan.neon` + non-blocking CI step; run once on a normal machine, then make it blocking. Until then PHP quality gates = Pint + PHPUnit + `php -l`. |
| R4 | Medium | **Queued notifications/mailables/listeners are not tenant-aware** (only jobs implementing `TenantAware` are). They fail closed if they touch scoped models, but a queued mail built from tenant data has no guard. | Tripwire test exists; build a tenant-aware base class when the first queued mail is added. |
| R5 | Low | **CI workflow has not run on GitHub**; actions are version-tagged, not SHA-pinned; browser e2e not in CI. | Pin SHAs, add e2e job with pinned Playwright. |
| R6 | Low | Invitations to *existing* users send no e-mail (they only see it in the picker); expired invitations are never pruned. | Add notification + scheduled prune. |
| R7 | Low | Tenant deletion/erasure path not built (`leads → tenant_memberships` FK is RESTRICT); user erasure must be anonymisation (audit FK is RESTRICT). | Needed for DPDP erasure workflow. |
| R8 | Low | Login throttle is per email+IP and per IP; a distributed attacker is limited only by the password policy (min 12, mixed case, number; breach check only in production). | Consider CAPTCHA/2FA. |
| R9 | Low | Concurrency of the last-admin guard is serialized by a tenant row lock but was verified only with a deterministic stale-state test, not a parallel load test. | Add a two-process test in Phase 5. |
| R10 | Info | MySQL trigger creation requires `SUPER`/`log_bin_trust_function_creators=1` for the migration account (documented). I enabled that global setting on the **disposable** MySQL in this container only. | — |

## 5. Test and gate results (final working tree)

| Gate | Result |
|---|---|
| PHPUnit (MySQL 8) | **133 passed, 0 failed, 486 assertions** |
| Vitest | 4 passed |
| `tsc --noEmit` | clean |
| `vite build` | succeeds |
| Pint | passes |
| `composer audit` / `npm audit` | no advisories / 0 vulnerabilities |
| Browser smoke (`npm run e2e`, Chromium, staging-mode server, CSP on) | **27 / 27 passed** |
| Mutation checks (earlier review): disabling the tenant scope / membership-status check / queue suspension check | 11 / 3 / 1 tests failed respectively; clean after restore |
| `crm:preflight` | bare production env → CRITICAL + exit 1; correct production env → exit 0 |
| Larastan / PHPStan | **not run** (see R3) |
| GitHub Actions CI | **not run** (YAML parsed; `composer validate`, `audit`, `npm ci --dry-run` run locally) |

## 6. Approval assessment

* **Phase 1a for staging / continued development: ready for approval**, subject to you accepting R3 (unverified static analysis) and R5 (CI not yet run remotely).
* **Production with real customer data: not yet** — blockers are the README go/no-go items: R1 (2FA recommended), production host verification (preflight, proxies, DB privileges, mail, backups/restore drill), CI green on GitHub, and legal review of privacy terms.
