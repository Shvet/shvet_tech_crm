/* Browser tests for the Phase 3 Meta Lead Ads integration (Playwright + Chromium) against a MOCK Graph server.
 *
 * EVERYTHING HERE IS MOCKED. It proves our OAuth/webhook/import/UI code works end to end under our assumptions about
 * Meta; it proves nothing about Meta itself. Setup (fresh seeded e2e DB, NON-local APP_ENV, QUEUE_CONNECTION=sync):
 *
 *   node e2e/mock-graph-server.cjs 8799 &
 *   cd public && APP_ENV=staging APP_DEBUG=false APP_URL=http://127.0.0.1:8766 DB_DATABASE=shvet_crm_e2e QUEUE_CONNECTION=sync \
 *     META_APP_ID=123 META_APP_SECRET=e2e-secret META_WEBHOOK_VERIFY_TOKEN=e2e-verify-token-0123456789 META_GRAPH_VERSION=v99.0 \
 *     META_GRAPH_BASE_URL=http://127.0.0.1:8799 META_OAUTH_DIALOG_BASE_URL=http://127.0.0.1:8799 \
 *     MAIL_MAILER=log SESSION_SECURE_COOKIE=false php -S 127.0.0.1:8766 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php &
 *   BASE_URL=http://127.0.0.1:8766 MOCK_URL=http://127.0.0.1:8799 META_APP_SECRET=e2e-secret E2E_DB=shvet_crm_e2e node e2e/meta.cjs
 */
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const crypto = require('crypto');
const fs = require('fs');
const path = require('path');

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8766';
const MOCK = process.env.MOCK_URL || 'http://127.0.0.1:8799';
const SECRET = process.env.META_APP_SECRET || 'e2e-secret';
const DB = process.env.E2E_DB || 'shvet_crm_e2e';
const SHOTS = process.env.SHOTS_DIR || path.join(__dirname, '../storage/e2e-screenshots');
const PASSWORD = 'Demo-Password-123';
const PAGE_ID = '100000000000001';
const FORM_ID = '700000000000001';
fs.mkdirSync(SHOTS, { recursive: true });

const results = [];
const problems = [];
let current = '';

const sql = (q) => execFileSync('mysql', ['-uroot', '-N', '-B', DB, '-e', q], { encoding: 'utf8' }).trim();
const count = (q) => Number(sql(q));
const assert = (cond, msg) => {
    if (!cond) throw new Error(msg);
};

function record(name, ok, detail = '') {
    results.push({ name, ok, detail });
    console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}${detail ? '  — ' + detail : ''}`);
}
async function check(name, fn) {
    current = name;
    try {
        await fn();
        record(name, true);
    } catch (e) {
        const where = (String(e.stack || '').match(/meta\.cjs:(\d+)/) || [])[1];
        record(name, false, `${String(e.message || e).split('\n')[0]}${where ? ` (meta.cjs:${where})` : ''}`);
    }
}

async function mock(pathname, body) {
    const res = await fetch(MOCK + pathname, body ? { method: 'POST', body: JSON.stringify(body) } : undefined);
    return res.json();
}

async function session(browser, label, email, opts = {}) {
    const context = await browser.newContext({ baseURL: BASE, ...opts });
    const page = await context.newPage();
    page.on('console', (m) => m.type() === 'error' && problems.push(`[${label}/${current}] console: ${m.text()}`));
    page.on('pageerror', (e) => problems.push(`[${label}/${current}] pageerror: ${e.message}`));
    await page.goto('/login');
    await page.getByLabel('E-mail').fill(email);
    await page.getByLabel('Password').fill(PASSWORD);
    await page.getByRole('button', { name: 'Sign in' }).click();
    await page.waitForLoadState('networkidle');
    return { context, page };
}

/** POST a webhook to the app exactly as Meta would (signed with the app secret). */
async function webhook(leadgenId, { signature } = {}) {
    const body = JSON.stringify({ object: 'page', entry: [{ id: PAGE_ID, time: 1, changes: [{ field: 'leadgen', value: { page_id: PAGE_ID, leadgen_id: leadgenId, form_id: FORM_ID, created_time: 1 } }] }] });
    const sig = signature ?? 'sha256=' + crypto.createHmac('sha256', SECRET).update(body).digest('hex');
    const res = await fetch(`${BASE}/webhooks/meta`, { method: 'POST', body, headers: { 'Content-Type': 'application/json', 'X-Hub-Signature-256': sig } });
    return res.status;
}

const lead = (id, name, phone, email) => ({
    id,
    created_time: '2026-10-28T09:30:00+0000',
    form_id: FORM_ID,
    platform: 'fb',
    campaign_name: 'E2E Campaign',
    field_data: [
        { name: 'full_name', values: [name] },
        { name: 'phone_number', values: [phone] },
        { name: 'email', values: [email] },
        { name: 'budget', values: ['under 1L'] },
    ],
});

(async () => {
    const browser = await chromium.launch();
    await mock('/__mock/reset');
    sql(`delete from meta_lead_events; delete from meta_form_mappings; delete from meta_pages; delete from meta_connections; delete from meta_oauth_states;`);

    // =========================================================== non-admins
    const agent = await session(browser, 'agent', 'agent@shvet.test');
    await check('agents do not see the integration and get 403 on direct access', async () => {
        const nav = agent.page.getByRole('navigation', { name: 'Main' });
        assert((await nav.getByRole('link', { name: 'Meta Lead Ads' }).count()) === 0, 'nav item visible to agent');
        const res = await agent.page.goto('/integrations/meta');
        assert(res.status() === 403, `expected 403 got ${res.status()}`);
    });
    await agent.context.close();

    // =========================================================== webhook basics (no session)
    await check('webhook handshake works and rejects a wrong token; bad signatures are rejected', async () => {
        const ok = await fetch(`${BASE}/webhooks/meta?hub.mode=subscribe&hub.verify_token=e2e-verify-token-0123456789&hub.challenge=424242`);
        assert(ok.status === 200 && (await ok.text()) === '424242', 'handshake failed');
        const bad = await fetch(`${BASE}/webhooks/meta?hub.mode=subscribe&hub.verify_token=wrong&hub.challenge=424242`);
        assert(bad.status === 403, `wrong token got ${bad.status}`);
        assert((await webhook('1', { signature: 'sha256=' + '0'.repeat(64) })) === 403, 'bad signature accepted');
        assert(count(`select count(*) from meta_lead_events`) === 0, 'rejected webhook stored an event');
    });

    // =========================================================== admin connect flow
    const admin = await session(browser, 'admin', 'admin@shvet.test');
    const p = admin.page;

    await check('admin connects through the (mock) OAuth dialog and sees the Page list', async () => {
        const nav = p.getByRole('navigation', { name: 'Main' });
        await nav.getByRole('link', { name: 'Meta Lead Ads' }).click();
        await p.waitForURL('**/integrations/meta');
        await p.getByRole('button', { name: 'Connect with Facebook' }).click();
        await p.waitForURL('**/integrations/meta', { timeout: 15000 });
        await p.getByText('Choose Pages to import leads from').waitFor();
        await p.screenshot({ path: path.join(SHOTS, 'meta-pick-pages.png'), fullPage: true });
        const html = await p.content();
        assert(!/MOCK-PAGE-TOKEN|MOCK-USER-TOKEN/.test(html), 'a token reached the browser');
        assert(count(`select count(*) from meta_connections where status='pending_selection'`) === 1, 'connection row missing');
        assert(count(`select count(*) from meta_oauth_states where used_at is not null`) === 1, 'state not burned');
    });

    await check('replaying the used OAuth callback URL does nothing', async () => {
        const graphCalls = async () => (await mock('/__mock/calls')).filter((c) => !c.includes('/__mock/')).length;
        const calls = await graphCalls();
        await p.goto('/integrations/meta/callback?code=MOCK-CODE&state=forged.value');
        await p.waitForLoadState('networkidle');
        assert(await p.getByText(/invalid or has expired/).count() === 1, 'no error shown');
        assert((await graphCalls()) === calls, 'forged state triggered Graph calls');
    });

    await check('admin selects the Page; tokens are encrypted at rest; subscription is confirmed', async () => {
        await p.goto('/integrations/meta');
        await p.getByRole('checkbox', { name: 'Mock Page' }).check();
        await p.getByRole('button', { name: 'Connect selected Pages' }).click();
        await p.getByRole('article', { name: 'Page Mock Page' }).waitFor();
        const stored = sql(`select access_token from meta_pages limit 1`);
        assert(stored && !stored.includes('MOCK-PAGE-TOKEN'), 'page token stored in clear text');
        assert(count(`select count(*) from meta_pages where subscription_status='subscribed'`) === 1, 'not subscribed');
        assert((await p.getByText('Mock Diwali form').count()) >= 1, 'form not listed');
    });

    await check('a form cannot be enabled without source and stage; a complete mapping saves', async () => {
        const f = p.getByRole('form', { name: 'Form Mock Diwali form' });
        await f.getByLabel('Import leads from this form').check();
        assert(await f.getByRole('button', { name: 'Save mapping' }).isDisabled(), 'save should be disabled');
        await f.getByLabel('Lead source').selectOption({ label: 'Facebook Ads' });
        await f.getByLabel('Starting stage').selectOption({ label: 'New' });
        await Promise.all([p.waitForResponse((r) => r.request().method() === 'PUT' && r.url().includes('/mappings/')), f.getByRole('button', { name: 'Save mapping' }).click()]);
        await p.waitForLoadState('networkidle');
        assert(count(`select count(*) from meta_form_mappings where is_enabled=1 and lead_source_id is not null`) === 1, 'mapping not saved');
    });

    // =========================================================== webhook → import
    await check('a signed webhook imports the lead end to end (queue sync) and it appears in the lead list', async () => {
        await mock('/__mock/lead', lead('900000000000101', 'Meera Kapoor', '+91 98200 11111', 'meera.kapoor@example.test'));
        assert((await webhook('900000000000101')) === 200, 'webhook not accepted');
        assert(count(`select count(*) from leads where external_provider='meta' and external_id='900000000000101'`) === 1, 'lead not created');
        assert(sql(`select status from meta_lead_events where leadgen_id='900000000000101'`) === 'imported', 'event not imported');
        await p.goto('/leads');
        await p.getByText('Meera Kapoor').first().waitFor();
        await p.screenshot({ path: path.join(SHOTS, 'meta-lead-imported.png'), fullPage: true });
    });

    await check('the same webhook delivered again creates no second lead', async () => {
        assert((await webhook('900000000000101')) === 200, 'redelivery rejected');
        assert(count(`select count(*) from leads where external_id='900000000000101'`) === 1, 'duplicate lead');
        assert(count(`select count(*) from meta_lead_events where leadgen_id='900000000000101'`) === 1, 'duplicate event');
    });

    await check('a different Meta lead with the same phone is flagged for review, not created; admin can create it anyway', async () => {
        await mock('/__mock/lead', lead('900000000000102', 'Meera K.', '+91 98200 11111', 'other@example.test'));
        assert((await webhook('900000000000102')) === 200, 'webhook not accepted');
        assert(sql(`select status from meta_lead_events where leadgen_id='900000000000102'`) === 'needs_review', 'not flagged');
        assert(count(`select count(*) from leads where external_id='900000000000102'`) === 0, 'duplicate was created silently');
        await p.goto('/integrations/meta');
        await p.getByText(/Possible duplicate of an existing lead/).first().waitFor();
        await p.screenshot({ path: path.join(SHOTS, 'meta-needs-review.png'), fullPage: true });
        await Promise.all([p.waitForResponse((r) => r.url().includes('/create-anyway')), p.getByRole('button', { name: 'Create anyway' }).first().click()]);
        await p.waitForLoadState('networkidle');
        assert(count(`select count(*) from leads where external_id='900000000000102'`) === 1, 'confirmed duplicate not created');
    });

    await check('a failing import is parked as failed with a sanitized message and can be retried after the cause is fixed', async () => {
        assert((await webhook('900000000000103')) === 200, 'webhook not accepted'); // mock has no such lead -> 404 not_found
        assert(sql(`select status from meta_lead_events where leadgen_id='900000000000103'`) === 'failed', 'not failed');
        await p.goto('/integrations/meta');
        await p.getByRole('button', { name: 'Retry' }).first().waitFor();
        const html = await p.content();
        assert(!/MOCK-PAGE-TOKEN|graph|127\.0\.0\.1:8799/i.test(html.replace(/127\.0\.0\.1:8766/g, '')), 'internal detail leaked into the page');
        await mock('/__mock/lead', lead('900000000000103', 'Late Lead', '+91 98200 22222', 'late@example.test'));
        await Promise.all([p.waitForResponse((r) => r.url().includes('/retry')), p.getByRole('button', { name: 'Retry' }).first().click()]);
        await p.waitForLoadState('networkidle');
        assert(sql(`select status from meta_lead_events where leadgen_id='900000000000103'`) === 'imported', 'retry did not import');
    });

    // =========================================================== disconnect
    await check('disconnect asks for confirmation; deleting the connection removes tokens and mappings but keeps leads', async () => {
        await p.goto('/integrations/meta');
        let confirmText = '';
        p.once('dialog', async (d) => {
            confirmText = d.message();
            await d.dismiss();
        });
        await p.getByRole('button', { name: 'Disconnect', exact: true }).first().click();
        assert(/Existing leads are kept/.test(confirmText), 'no confirmation dialog');
        assert(count(`select count(*) from meta_pages`) === 1, 'dismissed confirm still disconnected');

        p.once('dialog', (d) => d.accept());
        await Promise.all([p.waitForResponse((r) => r.request().method() === 'DELETE'), p.getByRole('button', { name: 'Disconnect', exact: true }).first().click()]);
        await p.waitForLoadState('networkidle');
        assert(count(`select count(*) from meta_pages`) === 0 && count(`select count(*) from meta_form_mappings`) === 0, 'tokens/mappings remain');
        assert(count(`select count(*) from leads where external_provider='meta'`) === 3, 'leads were removed');
        assert((await webhook('900000000000104')) === 200, 'webhook for a disconnected page should still be accepted');
        assert(sql(`select status from meta_lead_events where leadgen_id='900000000000104'`) === 'quarantined', 'not quarantined');
    });

    await check('mobile layout of the integration page does not overflow', async () => {
        const m = await session(browser, 'mobile', 'admin@shvet.test', { viewport: { width: 375, height: 800 } });
        await m.page.goto('/integrations/meta');
        await m.page.getByRole('heading', { name: 'Sync history' }).waitFor();
        assert(!(await m.page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth)), 'page overflows on mobile');
        await m.page.screenshot({ path: path.join(SHOTS, 'meta-mobile.png'), fullPage: true });
        await m.context.close();
    });

    await check('no unexpected console errors, page errors or CSP violations in the whole run', async () => {
        const real = [...new Set(problems)].filter((x) => !/Failed to load resource: the server responded with a status of (4\d\d)/.test(x));
        assert(real.length === 0, `${real.length} problem(s): ${real.slice(0, 3).join(' || ')}`);
    });

    await browser.close();
    const failed = results.filter((r) => !r.ok);
    console.log(`\n${results.length - failed.length}/${results.length} Meta browser checks passed (MOCK Graph server)`);
    fs.writeFileSync(path.join(SHOTS, 'meta-results.json'), JSON.stringify({ results, problems: [...new Set(problems)] }, null, 2));
    process.exit(failed.length ? 1 : 0);
})();
