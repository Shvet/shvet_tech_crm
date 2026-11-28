# Phase 5 — Everyday CRM tools

CSV export and import, bulk actions, daily follow-up reminder e-mails, pipeline stage settings and a sales report.
Everything here runs inside the current workspace only; the tenant scope fails closed as everywhere else.

## 1. What was built

| Feature | Where | Who (permission) |
| --- | --- | --- |
| Export leads to CSV | *Leads* → **Export CSV** (`GET /leads/export`) | Admin, Sales Manager (`leads.export`) |
| Import leads from CSV | *Leads* → **Import CSV** (`/leads/import`) | Admin (`leads.import`) |
| Bulk assign / change stage / archive | Checkboxes on *Leads* (`POST /leads/bulk`) | Admin, Sales Manager (`leads.bulk_update`) |
| Daily follow-up e-mail | Checkbox at the bottom of *Follow-ups*; command `crm:send-task-reminders` | Every member, for themselves |
| Pipeline stages | *Pipeline stages* (`/settings/pipeline`) | Admin (`pipeline.manage`) |
| Sales report | *Reports* (`/reports`) | Admin, Sales Manager (`reports.view`) |

- **Export** streams the list exactly as currently filtered (search, stage, source, owner, archived), and only the
  leads the user may see. Columns: `name, company, email, phone, stage, source, owner_email, owner, priority, value,
  created_at, last_contacted_at, archived_at, id`. UTF-8 with BOM so Excel opens it correctly. Cells that start with
  `= + - @`, tab or carriage return get a leading `'` (spreadsheet formula injection). Each export is audited
  (`leads.exported`, with the filters and row count). Capped at `CRM_EXPORT_MAX_ROWS`.
- **Import** reads the first row as headers (order free, unknown columns ignored, common aliases such as
  `full name`, `mobile`, `e-mail` accepted), so an exported file can be re-imported as is. Each row needs a name and a
  phone or e-mail. Stage and source match by name or key, owner by the e-mail of an active member. Rows whose phone or
  e-mail already belongs to a lead (or appears earlier in the same file) are skipped as duplicates unless *Import
  possible duplicates too* is ticked. **Check file** is a dry run that writes nothing. The whole file is validated for
  size and row count before any lead is created. Leads go through the same `CreateLead` action as the form, so stage
  history, activity log and phone normalisation are identical. Up to 100 row errors are listed by line number.
  One import at a time per user; audited as `leads.imported`.
- **Bulk actions** take up to 100 selected leads (one page). Each lead is checked against the same policy as the
  single-lead action and reuses the same action class, so history and activities are written per lead. Leads the user
  may not change, or that are already in the target state, are counted as skipped. Audited as `leads.bulk_<action>`.
- **Reminders.** Every 15 minutes the scheduler runs `crm:send-task-reminders`. For each active workspace, once its
  local time has passed `CRM_REMINDER_HOUR` (default 08:00), each active member with reminders on who has open
  follow-ups that are overdue or due today gets one e-mail listing them (with links). A row in
  `task_reminder_digests` (unique per workspace, user and local date) guarantees at most one e-mail per day, also when
  two schedulers race. Members switch it off per workspace; it is on by default.
- **Pipeline stages.** Rename, change outcome (open / won / lost), reorder, deactivate and reactivate, add new
  stages. The first active stage must be an *open* stage, because new leads start there; any change that breaks this
  is refused. Stages are never deleted, so stage history stays valid. Deactivated stages disappear from forms and
  filters; leads already in them stay until moved. All changes are audited.
- **Reports.** Presets (7, 30, 90 days, 12 months) or a custom range of up to two years, in the workspace's time zone.
  Summary (created, won, lost, win rate, won value, average days to win), the same figures by source and by owner,
  and the open pipeline by stage. Respects lead visibility like the rest of the app.

## 2. Limits (config/crm.php)

| Env | Default | Meaning |
| --- | --- | --- |
| `CRM_EXPORT_MAX_ROWS` | 50000 | Rows per export |
| `CRM_IMPORT_MAX_ROWS` | 2000 | Rows per import file |
| `CRM_IMPORT_MAX_KB` | 2048 | Import file size |
| `CRM_REMINDER_HOUR` | 8 | Local hour from which the daily e-mail is sent |

Rate limits: export and import 10 per minute, bulk 30 per minute, each per user and workspace (named limiters
`lead-export`, `lead-import`, `lead-bulk`).

## 3. Known limitations

- **Import is synchronous.** 2,000 rows take a few seconds; larger files must be split. A queued import with
  progress is possible later.
- **Reminder e-mails are sent inline**, not queued, and need the scheduler (`php artisan schedule:run` every minute)
  and a real mailer in production. With `MAIL_MAILER=log` nothing reaches anyone.
- **Won/lost in reports use the lead's current stage.** A lead that was won in the period and later moved back to an
  open stage no longer counts as won. Stage history is kept, so a history-based report can be added later.
- Bulk actions work on the current page selection only (no "select all matching").
- No undo for bulk actions; archive asks for confirmation and archived leads can be restored one by one.

## 4. Tests

- PHPUnit: `tests/Feature/Crm/LeadTransferTest.php`, `TaskReminderTest.php`, `PipelineSettingsTest.php`,
  `ReportTest.php`.
- Vitest: `resources/js/__tests__/leads-index.test.tsx`, `phase-d-pages.test.tsx`.
- Browser: `e2e/tools.cjs` (10 checks), `bash e2e/run.sh tools`, also run in CI.
