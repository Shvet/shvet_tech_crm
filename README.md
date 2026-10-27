# Shvet Technologies CRM

Multi-tenant, lightweight CRM: Meta (Facebook/Instagram) Lead Ads, WhatsApp Business Platform (Cloud API) shared inbox, sales pipeline, follow-ups and reporting.

**Status:** Phase 1a — foundation (Laravel 13, auth, tenancy, memberships, roles/permissions, isolation tests). Meta, WhatsApp and billing integrations are **not** implemented yet; nothing here has been tested against real Meta APIs.

- Architecture, multi-tenancy design, schema, phases: [`docs/CRM_IMPLEMENTATION_PLAN.md`](docs/CRM_IMPLEMENTATION_PLAN.md)
- Meta & WhatsApp prerequisites: [`docs/INTEGRATION_REQUIREMENTS.md`](docs/INTEGRATION_REQUIREMENTS.md)

## Stack
PHP 8.3 · Laravel 13 · MySQL 8 · Redis (queues/cache in production) · Inertia 3 + React 19 + TypeScript · Vite 8 · Tailwind 4 · spatie/laravel-permission (teams mode keyed by `tenant_id`).

## Local setup
```bash
composer install && npm install
cp .env.example .env && php artisan key:generate
# create a MySQL database + user, then set DB_* in .env
php artisan migrate --seed        # demo data: LOCAL ONLY (refuses to run in production)
npm run dev                       # or: npm run build
php artisan serve
```
Demo logins (password `Demo-Password-123`): `admin@shvet.test`, `manager@shvet.test`, `agent@shvet.test` (Shvet Technologies); `admin@acme.test`, `agent@acme.test` (Acme Demo Co); `multi@demo.test` (Agent in Shvet, Admin in Acme).

Create a real tenant (platform operation, server shell only):
```bash
php artisan tenants:provision "Company Name" owner@company.com --admin-name="Owner Name"
```
The owner receives a password-setup link by e-mail (configure `MAIL_*`).

## Quality gates
```bash
php artisan test                 # PHPUnit against MySQL (database shvet_crm_test)
vendor/bin/pint --test           # code style
npm run typecheck && npm test    # tsc + Vitest
npm run build
composer audit && npm audit
```
**Test database safety.** Tests refuse to run unless the database name ends in `_test` (they `migrate:fresh` it). Put credentials in the git-ignored `.env.testing` (see below) or in real environment variables; `phpunit.xml` deliberately contains none.
```bash
# .env.testing (ignored by git)
APP_ENV=testing
APP_KEY=...            # any generated key
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_DATABASE=shvet_crm_test
DB_USERNAME=...
DB_PASSWORD=...
```
**MySQL trigger privilege.** The audit log is made append-only with MySQL triggers (`2026_10_27_000500_protect_audit_logs`). With binary logging enabled (the MySQL 8 default) creating triggers needs the `SUPER` privilege or `log_bin_trust_function_creators=1` for the account that runs migrations. Run migrations as a privileged deploy account, never as the runtime application account.

**Static analysis.** `phpstan.neon` (Larastan, level 5) is wired into CI as a *non-blocking* step. It could not be installed or run in the authoring sandbox, so it is unverified: run `composer require --dev larastan/larastan && vendor/bin/phpstan analyse` once on a normal machine, fix or baseline the findings, then remove `continue-on-error` from `.github/workflows/ci.yml`.

### Browser smoke tests (Playwright)
`e2e/smoke.cjs` drives real Chromium through login, throttling, password reset, invitations, workspace switching, role enforcement, suspend/remove, session invalidation, CSP and mobile layout (27 checks). It mutates data, so use a throwaway database, a non-`local` `APP_ENV` (so the strict CSP is active) and the log mailer:
```bash
mysql -e "CREATE DATABASE shvet_crm_e2e"
DB_DATABASE=shvet_crm_e2e APP_ENV=local php artisan migrate --seed --force
npm run build
cd public && APP_ENV=staging APP_DEBUG=false APP_URL=http://127.0.0.1:8766 DB_DATABASE=shvet_crm_e2e \
  MAIL_MAILER=log MAIL_LOG_CHANNEL=maillog SESSION_SECURE_COOKIE=false \
  php -S 127.0.0.1:8766 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php &
cd .. && BASE_URL=http://127.0.0.1:8766 MAIL_LOG=storage/logs/mail.log npm run e2e
```
It is not part of CI yet (needs a pinned Playwright dependency and browsers).

## Tenancy rules for contributors
1. Every tenant-owned model uses `App\Tenancy\BelongsToTenant` (an architecture test enforces it for any model with a `tenant_id` column).
2. Queries fail closed without a tenant context. Cross the boundary only via `Tenancy::runAs()` / `Tenancy::withoutScope()` — never `withoutGlobalScope(s)` (a test greps for it).
3. Queued jobs implement `TenantAware`, use `QueuesForTenant`, and return `SetTenantContext` from `middleware()`. Queued notifications, mailables and listeners are **not** tenant-aware yet; a tripwire test fails if `Cache::`, `Storage::`, downloads, `Mail::to` or `->queue(` appear in `app/` without review.
4. Never read a tenant id from request input; it comes from the validated session via `ResolveTenant`.
5. Child tables get composite `(tenant_id, parent_id)` foreign keys.
6. Use **named** rate limiters (`RateLimiter::for`), never `throttle:N,M` on guest routes: unnamed limits share one counter per IP across routes.

## Deployment prerequisites (production)
| Area | Requirement |
|---|---|
| Host | Linux VPS, PHP 8.3 (`mbstring intl pdo_mysql redis openssl sodium`), MySQL 8.0+, Redis 7, Nginx + PHP-FPM, Node only for building assets (build in CI or on deploy, not at runtime) |
| TLS | Valid certificate on the public hostname; HTTP redirects to HTTPS; HSTS is sent automatically when the request is secure |
| Environment | `APP_ENV=production`, `APP_DEBUG=false`, `APP_KEY` generated once and backed up (losing it makes encrypted data and sessions unreadable), `APP_URL=https://<host>`, `CRM_ENFORCE_HOST=true` (default in production), `SESSION_SECURE_COOKIE=true`, `SESSION_ENCRYPT=true`, `SESSION_DRIVER=redis` or `database`, `QUEUE_CONNECTION=redis`, `CACHE_STORE=redis`, `LOG_LEVEL=warning`, `TRUSTED_PROXIES=<proxy ip/cidr>` |
| Mail | Real transport (`MAIL_MAILER=smtp|ses|...`) with SPF/DKIM/DMARC for the sender domain. `log`/`array` mailers are rejected at boot in production because reset links would be written to disk |
| Database accounts | **Three** accounts: (1) *runtime* app user with `SELECT, INSERT, UPDATE, DELETE` only (no DDL, no `TRIGGER`, no `SUPER`, not `root`); (2) *migration/deploy* user with DDL + `TRIGGER` (+ `SUPER` or `log_bin_trust_function_creators=1`); (3) backup user (`SELECT, LOCK TABLES, SHOW VIEW`). The runtime user cannot drop the audit triggers |
| Workers | `php artisan queue:work redis --tries=3 --max-time=3600` under Supervisor; restart on every deploy (`php artisan queue:restart`); `* * * * * php artisan schedule:run` in cron |
| Deploy steps | `composer install --no-dev --optimize-autoloader`, `npm ci && npm run build`, `php artisan migrate --force` (deploy account), `php artisan config:cache route:cache view:cache`, **`php artisan crm:preflight` must exit 0**, `queue:restart` |
| Files/permissions | Web root = `public/` only; `storage/` and `bootstrap/cache/` writable by the PHP user, nothing else; `.env` mode 600, never in the web root or git |
| Backups | Daily encrypted MySQL dump copied off-server, restore tested before go-live; `APP_KEY` stored separately in a secret manager |
| First tenant | `php artisan tenants:provision "Company" owner@company.com` (never run the demo seeder: it only runs in `local`/`testing`) |

## Security go / no-go checklist (Phase 1a foundation)
Tick every box on the production host before real users are invited. **Any unticked "Blocker" = no-go.**

| # | Check | Type | How to verify |
|---|---|---|---|
| 1 | `php artisan crm:preflight` exits 0 with no CRITICAL items | Blocker | run on the production host with production env |
| 2 | `php artisan test`, `pint --test`, `npm test`, `typecheck`, `build`, `composer audit`, `npm audit` all green in CI on the release commit | Blocker | CI run link |
| 3 | `npm run e2e` (27/27) passes against a staging copy running a non-local `APP_ENV` | Blocker | script output |
| 4 | Larastan step run green once and made blocking | Should | CI |
| 5 | Runtime DB account is not root and cannot `DROP TRIGGER`/DDL; audit triggers present (`SHOW TRIGGERS LIKE 'audit_logs'`) | Blocker | MySQL |
| 6 | `TRUSTED_PROXIES` set; `/up` and a login attempt show the real client IP in the audit log | Blocker | audit row `ip_address` |
| 7 | Response headers on `/login`: CSP, `X-Frame-Options: DENY`, `nosniff`, `Referrer-Policy`, HSTS (over HTTPS); session cookie `Secure; HttpOnly; SameSite=Lax` | Blocker | `curl -I`, browser devtools |
| 8 | Password-reset e-mail arrives from the real mail transport and links to the production host; a request with a forged `Host:` header gets 400 | Blocker | manual |
| 9 | Provisioned admin signs in, invites a user, user accepts; suspended user is locked out on next click | Blocker | manual |
| 10 | Backups restore into a scratch database; `APP_KEY` recovery documented | Blocker | restore drill |
| 11 | Admin 2FA available | Should (not implemented yet) | — |
| 12 | Log shipping/alerting for failed jobs and 5xx; audit log retention/off-box copy decided | Should | ops |
| 13 | Privacy notice, retention periods and data-processing terms reviewed by counsel (India DPDP) | Blocker for real customer data | legal |

## Production notes
See the deployment table above. Phase 5 adds the full operational runbook.
