# Phase 2 — Core CRM workflow

Baseline: Phase 1a (`58c3b9d`). Status: implemented and verified in the working tree, **not committed**.
Scope: leads, pipeline, activities & follow-ups, dashboard, team permissions. Not in scope (and not built): Meta Lead Ads, WhatsApp, billing, CSV import/export, bulk actions, reminders/notifications.

## 1. What was built

| Area | Capabilities | Key files |
|---|---|---|
| **Leads** | Create, view, edit, archive/restore, assign; search (name/company/e-mail/phone digits), filter (stage, source, owner, priority, archived), whitelisted sort (NULLs last, stable tie-break), pagination that keeps the query string; E.164 phone normalization (default region IN); duplicate warning on phone/e-mail with explicit "create anyway"; idempotent create (double click / retried POST → one lead) | `app/Http/Controllers/Lead*Controller.php`, `app/Actions/Leads/*`, `app/Services/Leads/*`, `app/Http/Requests/*Lead*`, `app/Http/Resources/LeadResource.php`, `resources/js/pages/Leads/*`, `resources/js/components/LeadForm.tsx` |
| **Pipeline** | Kanban board by stage (counts, per-column card cap with "view all"), move by keyboard-accessible select **or** drag-and-drop, optimistic-concurrency (`expected_stage_id`) so a stale board gets a conflict instead of overwriting; append-only stage history (actor + timestamp, initial placement recorded); deactivated stages stay visible while they hold leads | `app/Actions/Leads/ChangeLeadStage.php`, `app/Models/LeadStageHistory.php`, `app/Http/Controllers/PipelineController.php`, `resources/js/pages/Pipeline/Index.tsx` |
| **Activities** | Call, meeting, note, e-mail, other; actor + time (back-dating allowed, future refused); real contact moves `last_contacted_at` forward only; merged timeline of activities and stage moves | `app/Actions/Leads/LogActivity.php`, `app/Models/LeadActivity.php`, `Leads/Show.tsx` |
| **Follow-ups** | Title, description, priority, due date-time (entered in the **workspace timezone**, stored UTC), assignee, completion with outcome (leaves a timeline note), reopen; overdue / due-today / upcoming / completed views; assignee filter | `app/Actions/Tasks/*`, `app/Services/Tasks/TaskBuckets.php`, `app/Http/Controllers/TaskController.php`, `resources/js/pages/Tasks/Index.tsx`, `components/TaskRow.tsx` |
| **Dashboard** | Lead totals, new this week, open pipeline value, won/lost, unassigned (managers/admins only), stage distribution, recent activity, overdue / due-today / upcoming counts and lists — all derived from the viewer's visibility | `app/Services/Dashboard/DashboardMetrics.php`, `DashboardController.php`, `pages/Dashboard.tsx` |
| **Permissions** | One visibility rule (`LeadVisibility`) feeds lists, search, binding, tasks, pipeline and dashboard; policies add per-action rules | `app/Services/Leads/LeadVisibility.php`, `app/Policies/LeadPolicy.php`, `FollowUpTaskPolicy.php` |
| **Ops** | `php artisan crm:sync-roles` re-syncs role permissions for all existing tenants (adds `leads.archive`); demo seeder extended with realistic leads, history, activities and follow-ups (local/testing only) | `routes/console.php`, `database/seeders/DatabaseSeeder.php` |

## 2. Database changes (additive; both migrations roll back and re-apply cleanly — verified on a scratch DB)

`2026_10_28_000100_extend_leads_for_crm` — `leads` gains `company_name`, `priority`, `estimated_value DECIMAL(14,2)`, `created_by`, `submission_token`, `stage_changed_at`, `last_contacted_at`, `archived_at`, `archived_by`.
Indexes: `UNIQUE(tenant_id, created_by, submission_token)`, `(tenant_id, archived_at, pipeline_stage_id)`, `(tenant_id, owner_id, archived_at)`, `(tenant_id, created_at)`, `(tenant_id, full_name)`.
Composite FKs: `(tenant_id, created_by)` and `(tenant_id, archived_by)` → `tenant_memberships(tenant_id, user_id)`.

`2026_10_28_000200_create_lead_workflow_tables`
| Table | Notes |
|---|---|
| `lead_stage_history` | `lead_id`, `from_stage_id` (NULL = initial), `to_stage_id`, `changed_by`, `changed_at`. **Append-only**: model guard + MySQL `BEFORE UPDATE/DELETE` triggers. Indexes `(tenant_id, lead_id, changed_at)`, `(tenant_id, to_stage_id, changed_at)` |
| `lead_activities` | `lead_id`, `user_id` (actor), `type`, `subject`, `body`, `occurred_at`. Indexes `(tenant_id, lead_id, occurred_at)`, `(tenant_id, occurred_at)`, `(tenant_id, user_id)` |
| `follow_up_tasks` | ULID `public_id`, `lead_id`, `title`, `description`, `priority`, `due_at`, `assigned_to`, `created_by`, `status`, `completed_at/by`, `outcome`. Indexes `(tenant_id, status, due_at)`, `(tenant_id, assigned_to, status, due_at)`, `(tenant_id, lead_id, status)` |

Every foreign key in these tables is a **composite `(tenant_id, id)` key**, so the database itself rejects a task, activity, history row or lead that points at another tenant's lead, stage, source or member (proven in `CrmTenantIsolationTest`). Child rows of a lead cascade with it; stage/member references are RESTRICT.
New permission `leads.archive` (Admin, Sales Manager). Existing tenants need `php artisan crm:sync-roles`.

## 3. Role-permission matrix (enforced server-side; every cell is a test)

"Visible" = the user may see the lead. Sales Agents see **only leads they own**; unassigned and others' leads are 404 (indistinguishable from non-existent). Managers see the whole workspace (no team model yet).

| Action | Admin | Sales Manager | Sales Agent |
|---|---|---|---|
| List / search / open leads | all in workspace | all in workspace | **own leads only** |
| Open an archived lead / "show archived" | ✅ | ✅ | ❌ (404) |
| Create a lead | ✅ choose owner or leave unassigned | ✅ choose owner or leave unassigned | ✅ **always owner = self**; an `owner_id` in the request is rejected |
| Edit details (name, contact, company, value, priority, source) | ✅ | ✅ | own leads |
| Change stage / move on board | ✅ | ✅ | own leads |
| Change owner (assign / unassign) | ✅ | ✅ | ❌ 403 |
| Archive / restore | ✅ | ✅ | ❌ 403 |
| Log an activity | ✅ | ✅ | own leads |
| Schedule a follow-up | ✅ any assignee who can see the lead | ✅ same | own leads, **assigned to self only** |
| View follow-ups | all | all | tasks on their own leads |
| Complete / reopen / edit a follow-up | ✅ any | ✅ any | assignee or creator, on their own leads (a manager's task on their lead is read-only) |
| Dashboard figures | whole workspace + unassigned count | whole workspace + unassigned count | own leads only; no unassigned count |
| Filter by owner / assignee | ✅ | ✅ | ignored |
| User administration | ✅ | view only | ❌ |

Further rules: archived leads accept no edits, stage moves, activities, follow-ups or reassignment; a follow-up's assignee must be an **active** member who can **see** the lead; reassigning a lead moves the previous owner's open follow-ups to the new owner (or to the person who unassigned it); owners must be active members.

## 4. Test results (final working tree)

| Check | Result |
|---|---|
| PHPUnit (MySQL 8) | **307 passed**, 1765 assertions (Phase 2 added 174: matrix 36, lead management 42, tenant isolation 20, pipeline 16, activities+tasks 44, dashboard 10, action guards 5, seeder 1) |
| Vitest | **59 passed** (10 files; 55 new: pagination, lists/filters/sort, Kanban move + drag-drop + permissions, lead form incl. duplicate flow and double-submit, lead page, follow-ups, dashboard, formatting/timezones, navigation) |
| Browser, CRM workflow (`e2e/crm.cjs`, Chromium, strict CSP) | **18 / 18** |
| Browser, Phase 1a regression (`e2e/smoke.cjs`) | **27 / 27** |
| `tsc`, Pint, `vite build` | clean / pass / succeeds |
| `composer audit`, `npm audit`, `composer validate` | no advisories / 0 vulnerabilities / valid |
| Migrations up → down → up (scratch DB) | OK |
| Mutation checks on 10 security-critical guards (visibility scope, route binding, tenant-scoped validation, assignee visibility, stage history, active-owner guard, policy permission, dashboard scope, idempotency token, tenant-timezone buckets) | 9 caught immediately; **1 survived** (the active-owner check inside `AssignLead` was shadowed by the form request) → fixed, `CreateLead` given the same guard, direct action tests added, mutant re-run and caught |

Defects found and fixed during verification: idempotency token unique key allowed cross-user collisions (now per creator); a lead in a deactivated stage vanished from totals and the board; `AssignLead`/`CreateLead` trusted the form request for active owners; the pipeline page overflowed horizontally on mobile (absolutely-positioned `sr-only` labels outside a positioned ancestor); several test-suite mistakes (vacuous/duplicated assertions) were corrected rather than weakened.

**Unverified:** Larastan/PHPStan (still not installable here) and the GitHub Actions workflow have not run; the browser suites are manual (not in CI); no concurrent-request load test (row locks are used and sequential races are tested, parallel behaviour is not); nothing was tested against a real Meta/WhatsApp account (not built).

## 5. Known limitations and security risks

Functional
1. **No teams yet** — "Sales Manager" = whole workspace. `LeadVisibility` is the single place to add team scoping.
2. Unassigned leads are invisible to agents (the planned "agents may claim unassigned leads" setting is not implemented); managers must assign them.
3. Leads owned by a member who is later suspended/removed **stay assigned to them** (visible only to managers/admins) until reassigned — there is no automatic hand-over yet.
4. Stage configuration UI is not built (stages are seeded; deactivation works at data level and is handled in views).
5. Activities and follow-up history cannot be edited or deleted (append-only by design); leads are archived, never deleted. No hard-delete / data-erasure workflow yet (DPDP).
6. No bulk assign/update, CSV import/export, duplicate merge, notifications or reminder e-mails — due follow-ups appear on the dashboard and follow-ups page only.
7. Lead edits are last-write-wins (optimistic concurrency exists only for stage moves).
8. Search uses `LIKE '%term%'` (fine for tens of thousands of leads; move to FULLTEXT/search service beyond that). The Kanban shows 30 cards per column with a "view all" link.
9. Date-times are entered in the **workspace** timezone, not the browser's; drag-and-drop is mouse-oriented (the select is the touch/keyboard path).

Security / privacy
10. Notes, activities and lead PII are stored unencrypted in MySQL (encryption was decided for WhatsApp message bodies only).
11. Audit rows are written for assignment, archive and restore (plus Phase 1a events); lead create/edit/stage/task events rely on history/timeline tables and are not in `audit_logs`.
12. Numeric ids of stages, sources and member users appear in payloads (always validated against the current tenant; leads/tasks use ULIDs).
13. Rate limiting for lead writes is the generic per-user 240/min; there is no per-tenant creation quota.
14. Carry-over risks from `docs/PHASE_1A_SECURITY_REVIEW.md` (2FA, tamper-evident audit, queued-mail tenant awareness, CI not run) are unchanged.

## 6. Manual QA checklist (≈20 min; use `php artisan migrate:fresh --seed` on a **local** database; password `Demo-Password-123`)

Agent (`agent@shvet.test`)
- [ ] Nav shows Dashboard, Leads, Pipeline, Follow-ups (no Team). Dashboard "Total leads" = 4; "Overdue follow-ups" = 1.
- [ ] Leads list shows 4 leads. Open one of them; try a manager's lead URL (e.g. Deepa Menon) → 404 page.
- [ ] New lead: submit with phone `12345` → inline error. Fix and create with `98765 00001` → lead page shows ₹ value, owner = you, timeline "Started in New".
- [ ] Create another lead with the same phone → duplicate warning with a link; **Create anyway** is a separate click. Double-click Create lead on a fresh form → only one lead.
- [ ] Search `nair`, a phone fragment, and a stage filter; click a column header to sort; copy the URL into a new tab — same results.
- [ ] Pipeline: move a card with its select, then drag another card to a different column; reload — both stuck. Open a second browser as the manager, move the same lead, then move it again in the stale tab → conflict message, board refreshes.
- [ ] Lead page: log a call (appears in timeline, "Last contacted" updates); schedule a follow-up for tomorrow; complete it with an outcome → timeline shows it; Reopen works.
- [ ] Follow-ups page: tabs Overdue / Due today / Upcoming / Completed show counts; overdue item has a visible "Overdue" label.

Manager (`manager@shvet.test`)
- [ ] Leads list shows every lead; unassigned counter on the dashboard. Open an unassigned lead, assign it to Shvet Agent → agent can now open it; reassign to yourself → agent gets 404 again.
- [ ] Archive a lead (cancel the confirm first → nothing happens; confirm → gone from list, board, dashboard). "Show archived" → open it: read-only banner, stage locked; Restore.
- [ ] Schedule a follow-up on an agent's lead assigned to the agent; try assigning to a different agent → refused.

Isolation
- [ ] Sign in as `admin@acme.test`: only "Acme Only Lead" is listed; paste a Shvet lead URL → 404; search `priya` → nothing.
- [ ] `multi@demo.test`: choose Acme (Admin) vs Shvet (Agent) from the workspace switcher; confirm visible data and available actions change with the workspace.

Responsive / a11y
- [ ] At 375 px width: no horizontal page scroll; Pipeline scrolls sideways inside its own area; Menu button opens navigation.
- [ ] Keyboard only: tab through the lead form; every error message is announced next to its field; Kanban cards can be moved with the select.
