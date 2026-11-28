/* Browser tests for the Phase D CRM tools: CSV export/import, bulk actions, follow-up reminder toggle, pipeline stage
 * settings and reports. Run through the shared runner (fresh demo seed, non-local APP_ENV so the strict CSP applies):
 *
 *   bash e2e/run.sh tools
 *
 * It mutates that database and reads rows back with the mysql CLI.
 */
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const fs = require('fs');
const os = require('os');
const path = require('path');

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8766';
const DB = process.env.E2E_DB || 'shvet_crm_e2e';
const SHOTS = process.env.SHOTS_DIR || path.join(__dirname, '../storage/e2e-screenshots');
const PASSWORD = 'Demo-Password-123';
fs.mkdirSync(SHOTS, { recursive: true });

const results = [];
const problems = [];
let current = '';

const sql = (q) => execFileSync('mysql', ['-uroot', '-N', '-B', DB, '-e', q], { encoding: 'utf8' }).trim();
const count = (q) => Number(sql(q));
const SHVET = `(select id from tenants where slug='shvet')`;

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
        const where = (String(e.stack || '').match(/tools\.cjs:(\d+)/) || [])[1];
        record(name, false, `${String(e.message || e).split('\n')[0]}${where ? ` (tools.cjs:${where})` : ''}`);
    }
}
const assert = (cond, msg) => {
    if (!cond) throw new Error(msg);
};

async function session(browser, label, email, opts = {}) {
    const context = await browser.newContext({ baseURL: BASE, acceptDownloads: true, ...opts });
    const page = await context.newPage();
    page.on('console', (m) => m.type() === 'error' && problems.push(`[${label}/${current}] console: ${m.text()}`));
    page.on('pageerror', (e) => problems.push(`[${label}/${current}] pageerror: ${e.message}`));
    page.on('dialog', (d) => d.accept());
    await page.goto('/login');
    await page.getByLabel('E-mail').fill(email);
    await page.getByLabel('Password').fill(PASSWORD);
    await page.getByRole('button', { name: 'Sign in' }).click();
    await page.waitForLoadState('networkidle');
    return { context, page };
}

async function act(page, fn, method, part = '') {
    const [res] = await Promise.all([page.waitForResponse((r) => r.request().method() === method && r.url().includes(part)), fn()]);
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(300);
    return res;
}

(async () => {
    const browser = await chromium.launch();
    const admin = await session(browser, 'admin', 'admin@shvet.test');
    const manager = await session(browser, 'manager', 'manager@shvet.test');
    const agent = await session(browser, 'agent', 'agent@shvet.test');
    const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'crm-e2e-'));

    await check('navigation: admins see Reports and Pipeline stages, agents see neither', async () => {
        await admin.page.goto('/dashboard');
        const nav = admin.page.getByRole('navigation', { name: 'Main' });
        assert((await nav.getByRole('link', { name: 'Reports' }).count()) === 1, 'admin: no Reports link');
        assert((await nav.getByRole('link', { name: 'Pipeline stages' }).count()) === 1, 'admin: no Pipeline stages link');
        await agent.page.goto('/dashboard');
        const agentNav = agent.page.getByRole('navigation', { name: 'Main' });
        assert((await agentNav.getByRole('link', { name: 'Reports' }).count()) === 0, 'agent sees Reports');
        assert((await agentNav.getByRole('link', { name: 'Pipeline stages' }).count()) === 0, 'agent sees Pipeline stages');
        assert((await agent.page.goto('/reports')).status() === 403, 'agent can open /reports');
    });

    let exported = '';
    await check('manager exports the filtered lead list as a CSV download', async () => {
        await manager.page.goto('/leads');
        const [download] = await Promise.all([manager.page.waitForEvent('download'), manager.page.getByRole('link', { name: 'Export CSV' }).click()]);
        exported = path.join(tmp, download.suggestedFilename());
        await download.saveAs(exported);
        const lines = fs.readFileSync(exported, 'utf8').replace(/^﻿/, '').trim().split('\n');
        assert(lines[0].startsWith('name,company,email,phone,stage'), `header: ${lines[0]}`);
        const visible = count(`select count(*) from leads where tenant_id=${SHVET} and archived_at is null`);
        assert(lines.length - 1 === visible, `rows ${lines.length - 1} vs ${visible}`);
        assert(count(`select count(*) from audit_logs where action='leads.exported'`) >= 1, 'export not audited');
        assert((await agent.page.goto('/leads')) && (await agent.page.getByRole('link', { name: 'Export CSV' }).count()) === 0, 'agent sees Export');
    });

    await check('admin imports a CSV: "Check file" writes nothing, Import creates leads and lists bad rows', async () => {
        const file = path.join(tmp, 'import.csv');
        fs.writeFileSync(file, 'name,phone,email,stage,owner_email\nImport One,9811100001,,Qualified,agent@shvet.test\nImport Two,,import.two@example.test,,\nX,,,,\n');
        const before = count(`select count(*) from leads`);
        await admin.page.goto('/leads/import');
        await admin.page.getByLabel(/CSV file/).setInputFiles(file);
        await act(admin.page, () => admin.page.getByRole('button', { name: 'Check file' }).click(), 'POST', '/leads/import');
        const result = admin.page.getByRole('status', { name: 'Import result' });
        assert(await result.getByText('Check finished. Nothing was imported yet.').isVisible(), 'no dry-run result');
        assert(count(`select count(*) from leads`) === before, 'dry run wrote rows');

        await admin.page.getByLabel(/CSV file/).setInputFiles(file);
        await act(admin.page, () => admin.page.getByRole('button', { name: 'Import' }).click(), 'POST', '/leads/import');
        assert(await result.getByText('Created: 2').isVisible(), 'created count');
        assert(await result.getByText(/Line 4:/).isVisible(), 'row error not listed');
        assert(sql(`select (select email from users where id=owner_id) from leads where full_name='Import One'`) === 'agent@shvet.test', 'owner not set');
        assert(sql(`select s.\`key\` from leads l join pipeline_stages s on s.id=l.pipeline_stage_id where l.full_name='Import One'`) === 'qualified', 'stage not set');
    });

    await check('re-importing the exported file creates nothing (every row is a duplicate)', async () => {
        const before = count(`select count(*) from leads`);
        await admin.page.goto('/leads/import');
        await admin.page.getByLabel(/CSV file/).setInputFiles(exported);
        await act(admin.page, () => admin.page.getByRole('button', { name: 'Import' }).click(), 'POST', '/leads/import');
        const result = admin.page.getByRole('status', { name: 'Import result' });
        assert(await result.getByText('Created: 0').isVisible(), `something was created: ${(await result.innerText()).replace(/\s+/g, ' ').slice(0, 300)}`);
        assert(count(`select count(*) from leads`) === before, 'row count changed');
    });

    await check('manager bulk-assigns two selected leads and moves them to a stage', async () => {
        await manager.page.goto('/leads?q=Import');
        await manager.page.getByLabel('Select all leads on this page').check();
        const bar = manager.page.getByRole('region', { name: 'Bulk actions' });
        assert(await bar.getByText('2 selected').isVisible(), 'selection count');
        await bar.getByLabel('Action').selectOption('assign');
        await bar.getByLabel('New owner').selectOption({ label: 'Shvet Manager' });
        await act(manager.page, () => bar.getByRole('button', { name: 'Apply' }).click(), 'POST', '/leads/bulk');
        assert(await manager.page.getByText('2 leads updated.').isVisible(), 'no status message');
        assert(count(`select count(*) from leads where full_name like 'Import %' and owner_id=(select id from users where email='manager@shvet.test')`) === 2, 'not assigned');

        await manager.page.getByLabel('Select all leads on this page').check();
        await bar.getByLabel('Action').selectOption('stage');
        await bar.getByLabel('New stage').selectOption({ label: 'Negotiation' });
        await act(manager.page, () => bar.getByRole('button', { name: 'Apply' }).click(), 'POST', '/leads/bulk');
        assert(count(`select count(*) from leads l join pipeline_stages s on s.id=l.pipeline_stage_id where l.full_name like 'Import %' and s.\`key\`='negotiation'`) === 2, 'not moved');
        assert(count(`select count(*) from lead_stage_history h join leads l on l.id=h.lead_id where l.full_name like 'Import %'`) >= 4, 'no history');
    });

    await check('follow-ups page: a member switches the daily reminder e-mail off and the choice sticks', async () => {
        await agent.page.goto('/tasks');
        const box = agent.page.getByLabel(/E-mail me a daily summary/);
        assert(await box.isChecked(), 'on by default');
        await act(agent.page, () => box.uncheck(), 'PATCH', '/tasks/reminders');
        await agent.page.reload();
        assert(!(await agent.page.getByLabel(/E-mail me a daily summary/).isChecked()), 'did not persist');
        assert(count(`select task_reminders from tenant_memberships where user_id=(select id from users where email='agent@shvet.test') and tenant_id=${SHVET}`) === 0, 'db not updated');
    });

    await check('pipeline stages: admin adds a stage, moves it up, and the lead form offers it; won-first is refused', async () => {
        await admin.page.goto('/settings/pipeline');
        await admin.page.getByLabel('Name', { exact: true }).fill('Site Visit');
        await act(admin.page, () => admin.page.getByRole('button', { name: 'Add stage' }).click(), 'POST', '/settings/pipeline/stages');
        const list = admin.page.getByRole('list', { name: 'Pipeline stages' });
        assert((await list.getByRole('listitem', { name: 'Site Visit' }).count()) === 1, 'stage not listed');
        await act(admin.page, () => admin.page.getByRole('button', { name: 'Move Site Visit up' }).click(), 'PUT', '/settings/pipeline/order');
        const order = sql(`select group_concat(\`key\` order by position) from pipeline_stages where tenant_id=${SHVET}`).split(',');
        assert(order.indexOf('site_visit') === order.length - 2, `order: ${order}`);

        await admin.page.goto('/leads/create');
        assert((await admin.page.getByLabel('Stage').locator('option', { hasText: 'Site Visit' }).count()) === 1, 'not offered in the lead form');

        await admin.page.goto('/settings/pipeline');
        for (let i = 0; i < 8; i++) {
            const up = admin.page.getByRole('button', { name: 'Move Won up' });
            if (await up.isDisabled()) break;
            const res = await act(admin.page, () => up.click(), 'PUT', '/settings/pipeline/order');
            if ((await admin.page.getByText(/first active stage must be an open stage/).count()) > 0) break;
            assert(res.status() < 500, 'server error');
        }
        const first = sql(`select s.outcome from pipeline_stages s where tenant_id=${SHVET} and is_active=1 order by position limit 1`);
        assert(first === 'open', `first active stage is ${first}`);
        assert(await admin.page.getByText(/first active stage must be an open stage/).isVisible(), 'no explanation shown');
    });

    await check('reports: numbers render for the default period and presets switch the range', async () => {
        await manager.page.goto('/reports');
        assert(await manager.page.getByRole('button', { name: 'Last 30 days' }).getAttribute('aria-pressed') === 'true', 'default preset');
        const created = await manager.page.getByText('Leads created', { exact: true }).locator('xpath=following-sibling::dd').innerText();
        assert(Number(created) >= 2, `created ${created}`);
        assert(await manager.page.getByRole('rowheader', { name: 'Shvet Manager' }).first().isVisible(), 'owner breakdown missing');
        await act(manager.page, () => manager.page.getByRole('button', { name: 'Last 7 days' }).click(), 'GET', '/reports');
        assert(manager.page.url().includes('range=7'), 'preset not in URL');
        await manager.page.screenshot({ path: path.join(SHOTS, 'tools-reports.png'), fullPage: true });
    });

    await check('mobile: the lead list with bulk checkboxes and the reports page do not overflow', async () => {
        const m = await session(browser, 'mobile', 'manager@shvet.test', { viewport: { width: 375, height: 800 } });
        for (const url of ['/leads', '/reports', '/settings/pipeline']) {
            await m.page.goto(url);
            if (url === '/settings/pipeline') continue; // managers are refused; nothing to measure
            assert(!(await m.page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth)), `${url} overflows`);
        }
        await m.context.close();
    });

    await check('no unexpected console errors, page errors or CSP violations in the whole run', async () => {
        const real = [...new Set(problems)].filter((p) => !/Failed to load resource: the server responded with a status of (4\d\d)/.test(p));
        assert(real.length === 0, `${real.length} problem(s): ${real.slice(0, 3).join(' || ')}`);
    });

    await browser.close();
    const failed = results.filter((r) => !r.ok);
    console.log(`\n${results.length - failed.length}/${results.length} tools browser checks passed`);
    fs.writeFileSync(path.join(SHOTS, 'tools-results.json'), JSON.stringify({ results, problems: [...new Set(problems)] }, null, 2));
    process.exit(failed.length ? 1 : 0);
})();
