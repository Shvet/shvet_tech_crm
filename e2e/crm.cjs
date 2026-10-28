/* Browser tests for the Phase 2 CRM workflow (Playwright + Chromium). Same setup rules as e2e/smoke.cjs:
 *
 *   BASE_URL=http://127.0.0.1:8766 E2E_DB=shvet_crm_e2e node e2e/crm.cjs
 *
 * Needs the demo seed (php artisan migrate --seed on a local-env scratch database) and a NON-local APP_ENV server so
 * the strict CSP is active. It mutates that database. Looks rows up with the mysql CLI (root, local socket).
 */
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const fs = require('fs');
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
const leadId = (name) => sql(`select public_id from leads where full_name='${name}' limit 1`);
const count = (q) => Number(sql(q));

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
        const where = (String(e.stack || '').match(/crm\.cjs:(\d+)/) || [])[1];
        record(name, false, `${String(e.message || e).split('\n')[0]}${where ? ` (crm.cjs:${where})` : ''}`);
    }
}
const assert = (cond, msg) => {
    if (!cond) throw new Error(msg);
};

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

/** Run an action and wait for the resulting non-GET request plus Inertia's re-render. */
async function act(page, fn, method, part = '') {
    const [res] = await Promise.all([page.waitForResponse((r) => r.request().method() === method && r.url().includes(part)), fn()]);
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(400);
    return res;
}

(async () => {
    const browser = await chromium.launch();

    // =========================================================== sales agent
    const agent = await session(browser, 'agent', 'agent@shvet.test');

    await check('agent navigation shows CRM sections but not Team; dashboard numbers are scoped to their leads', async () => {
        await agent.page.waitForURL('**/dashboard');
        const nav = agent.page.getByRole('navigation', { name: 'Main' });
        for (const name of ['Dashboard', 'Leads', 'Pipeline', 'Follow-ups']) assert((await nav.getByRole('link', { name }).count()) === 1, `missing nav ${name}`);
        assert((await nav.getByRole('link', { name: 'Team' }).count()) === 0, 'Team must be hidden');
        assert((await agent.page.getByText('Total leads', { exact: true }).locator('xpath=following-sibling::p').innerText()) === '4', 'agent should see 4 leads');
        assert((await agent.page.getByText('Overdue follow-ups', { selector: 'p' }).locator('xpath=following-sibling::p').innerText()) === '1', 'agent has 1 overdue follow-up');
        await agent.page.screenshot({ path: path.join(SHOTS, 'crm-agent-dashboard.png'), fullPage: true });
    });

    await check('agent lead list shows only own leads; other leads are 404 by direct URL (no existence leak)', async () => {
        await agent.page.goto('/leads');
        const rows = await agent.page.locator('tbody tr').count();
        assert(rows === 4, `expected 4 rows, got ${rows}`);
        assert((await agent.page.getByText('Deepa Menon').count()) === 0, "manager's lead visible to agent");
        for (const name of ['Deepa Menon', 'Neha Gupta', 'Sunita Rao']) {
            const res = await agent.page.goto(`/leads/${leadId(name)}`);
            assert(res.status() === 404, `${name}: expected 404 got ${res.status()}`);
        }
        const nonexistent = await agent.page.goto('/leads/01ARZ3NDEKTSV4RRFFQ69G5FAV');
        assert(nonexistent.status() === 404, 'non-existent id must look identical (404)');
    });

    await check('agent cannot call manager-only endpoints (assign/archive) even with a valid session and CSRF', async () => {
        const own = leadId('Priya Nair');
        await agent.page.goto(`/leads/${own}`);
        const statuses = await agent.page.evaluate(async (id) => {
            const token = decodeURIComponent(document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN=')).split('=')[1]);
            const h = { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': token };
            const archive = await fetch(`/leads/${id}/archive`, { method: 'POST', headers: h });
            const owner = await fetch(`/leads/${id}/owner`, { method: 'PATCH', headers: h, body: JSON.stringify({ owner_id: null }) });
            return [archive.status, owner.status];
        }, own);
        assert(statuses[0] === 403 && statuses[1] === 403, `statuses ${statuses}`);
        assert(count(`select count(*) from leads where full_name='Priya Nair' and archived_at is null and owner_id is not null`) === 1, 'lead was altered');
    });

    await check('creating a lead: normalized phone, owner = creator, redirected to the lead; rapid double-click creates ONE lead', async () => {
        await agent.page.goto('/leads/create');
        assert((await agent.page.getByLabel('Owner').count()) === 0, 'agents must not see an owner picker');
        await agent.page.getByLabel('Full name').fill('Browser Test Lead');
        await agent.page.getByLabel('Phone').fill('98765 00001');
        await agent.page.getByLabel('E-mail').fill('browser.test@example.test');
        await agent.page.getByLabel('Company').fill('Browser Co');
        await agent.page.getByLabel('Priority').selectOption('high');
        await agent.page.getByLabel('Estimated value (₹)').fill('125000.50');
        await agent.page.getByLabel('First note (optional)').fill('Created from the browser test.');
        await agent.page.getByRole('button', { name: 'Create lead' }).dblclick();
        await agent.page.waitForURL(/\/leads\/[0-9A-Z]{26}$/);
        await agent.page.waitForLoadState('networkidle');
        assert((await agent.page.content()).includes('Lead created.') || (await agent.page.content()).includes('already created'), 'no confirmation');
        assert(count(`select count(*) from leads where email='browser.test@example.test'`) === 1, 'double click created more than one lead');
        assert(sql(`select concat(phone_e164,'|',priority,'|',estimated_value,'|',(select email from users where id=owner_id)) from leads where email='browser.test@example.test'`) === '+919876500001|high|125000.50|agent@shvet.test', 'stored data');
        assert(count(`select count(*) from lead_stage_history h join leads l on l.id=h.lead_id where l.email='browser.test@example.test' and h.from_stage_id is null`) === 1, 'initial stage history');
        await agent.page.screenshot({ path: path.join(SHOTS, 'crm-lead-show.png'), fullPage: true });
    });

    await check('form validation errors are shown next to the fields and nothing is saved', async () => {
        await agent.page.goto('/leads/create');
        await agent.page.getByLabel('Full name').fill('X');
        await agent.page.getByLabel('Phone').fill('12345');
        await agent.page.getByRole('button', { name: 'Create lead' }).click();
        await agent.page.getByText(/valid phone number/).waitFor();
        assert((await agent.page.getByLabel('Phone').getAttribute('aria-invalid')) === 'true', 'aria-invalid');
        assert(count(`select count(*) from leads where full_name='X'`) === 0, 'invalid lead saved');
    });

    await check('duplicate warning appears, offers the match, and "Create anyway" is an explicit second step', async () => {
        await agent.page.goto('/leads/create');
        await agent.page.getByLabel('Full name').fill('Browser Test Lead Again');
        await agent.page.getByLabel('Phone').fill('+91 98765 00001');
        await agent.page.getByRole('button', { name: 'Create lead' }).click();
        await agent.page.getByText(/already exists/).first().waitFor();
        await agent.page.getByRole('link', { name: 'Browser Test Lead' }).waitFor();
        assert(count(`select count(*) from leads where full_name='Browser Test Lead Again'`) === 0, 'saved before confirmation');
        await act(agent.page, () => agent.page.getByRole('button', { name: 'Create anyway' }).click(), 'POST', '/leads');
        await agent.page.waitForURL(/\/leads\/[0-9A-Z]{26}$/);
        assert(count(`select count(*) from leads where full_name='Browser Test Lead Again'`) === 1, 'not created after confirmation');
    });

    await check('search, filter and sort work and keep the URL shareable', async () => {
        await agent.page.goto('/leads');
        await agent.page.getByLabel('Search').fill('nair');
        await agent.page.getByRole('button', { name: 'Search' }).click();
        await agent.page.waitForURL(/q=nair/);
        assert((await agent.page.locator('tbody tr').count()) === 1, 'search by name');
        await agent.page.getByRole('button', { name: 'Clear filters' }).click();
        await agent.page.waitForURL((u) => !u.search);
        await agent.page.getByLabel('Search').fill('9811122');
        await agent.page.getByRole('button', { name: 'Search' }).click();
        await agent.page.waitForURL(/q=9811122/);
        assert((await agent.page.locator('tbody tr').count()) === 1, 'search by phone digits');
        await agent.page.getByRole('button', { name: 'Clear filters' }).click();
        await agent.page.getByLabel('Stage').selectOption({ label: 'Won' });
        await agent.page.waitForURL(/stage=/);
        assert((await agent.page.locator('tbody tr').count()) === 1, 'stage filter');
        await agent.page.getByRole('button', { name: 'Clear filters' }).click();
        await agent.page.getByRole('button', { name: /^Name/ }).click();
        await agent.page.waitForURL(/sort=name/);
        const names = await agent.page.locator('tbody tr td:first-child a').allInnerTexts();
        assert(JSON.stringify(names) === JSON.stringify([...names].sort((a, b) => a.localeCompare(b))), `not sorted: ${names}`);
    });

    await check('pipeline board: columns with counts; move a card with the select; change persists after reload', async () => {
        await agent.page.goto('/pipeline');
        assert((await agent.page.getByRole('listitem', { name: /^New, / }).count()) === 1, 'New column');
        await act(agent.page, () => agent.page.getByLabel('Move Priya Nair to stage').selectOption({ label: 'Contacted' }), 'PATCH', '/stage');
        await agent.page.reload();
        const col = agent.page.getByRole('listitem', { name: /^Contacted, / });
        assert((await col.getByRole('link', { name: 'Priya Nair' }).count()) === 1, 'card did not move');
        assert(sql(`select s.key from leads l join pipeline_stages s on s.id=l.pipeline_stage_id where l.full_name='Priya Nair'`) === 'contacted', 'db stage');
        assert(count(`select count(*) from lead_stage_history h join leads l on l.id=h.lead_id where l.full_name='Priya Nair' and h.changed_by=(select id from users where email='agent@shvet.test')`) >= 1, 'history actor');
        await agent.page.screenshot({ path: path.join(SHOTS, 'crm-pipeline.png'), fullPage: true });
    });

    await check('pipeline board: drag and drop a card to another column', async () => {
        await agent.page.goto('/pipeline');
        const card = agent.page.getByRole('link', { name: 'Priya Nair' }).locator('xpath=ancestor::li[1]');
        const target = agent.page.getByRole('listitem', { name: /^Qualified, / });
        await act(agent.page, () => card.dragTo(target), 'PATCH', '/stage');
        assert(sql(`select s.key from leads l join pipeline_stages s on s.id=l.pipeline_stage_id where l.full_name='Priya Nair'`) === 'qualified', 'drag/drop did not persist');
        assert(count(`select count(*) from lead_stage_history h join leads l on l.id=h.lead_id where l.full_name='Priya Nair'`) === 3, 'expected initial + 2 moves');
    });

    await check('lead page: log a call, schedule a follow-up, complete it with an outcome; timeline records all of it', async () => {
        await agent.page.goto(`/leads/${leadId('Browser Test Lead')}`);
        await agent.page.getByLabel('Type').selectOption('call');
        await agent.page.getByLabel('Subject').fill('Intro call');
        await agent.page.getByLabel('Details').fill('Interested in the premium plan.');
        await act(agent.page, () => agent.page.getByRole('button', { name: 'Record activity' }).click(), 'POST', '/activities');
        await agent.page.getByText('Interested in the premium plan.').waitFor();
        assert(count(`select count(*) from leads where email='browser.test@example.test' and last_contacted_at is not null`) === 1, 'last_contacted_at');

        // The scheduler is open by default when a lead has no follow-ups yet; only expand it if it is collapsed.
        if (!(await agent.page.locator('details[open]').count())) await agent.page.getByText('Schedule a follow-up').click();
        await agent.page.getByLabel('Follow-up').fill('Send quotation');
        await act(agent.page, () => agent.page.getByRole('button', { name: 'Schedule follow-up' }).click(), 'POST', '/tasks');
        await agent.page.getByText('Send quotation').first().waitFor();

        await agent.page.getByRole('button', { name: 'Complete' }).first().click();
        await agent.page.getByLabel('Outcome (optional)').fill('Quote accepted');
        await act(agent.page, () => agent.page.getByRole('button', { name: 'Mark complete' }).click(), 'POST', '/complete');
        await agent.page.getByText('Outcome: Quote accepted').waitFor();
        await agent.page.getByText('Follow-up completed: Send quotation').waitFor();
        await agent.page.screenshot({ path: path.join(SHOTS, 'crm-lead-timeline.png'), fullPage: true });
    });

    await check('follow-ups page: Overdue tab lists the overdue task with a visible flag and counts', async () => {
        await agent.page.goto('/tasks');
        await agent.page.getByText('Send product brochure').waitFor();
        assert((await agent.page.getByText('Overdue', { exact: true }).count()) >= 1, 'overdue flag');
        const nav = agent.page.getByRole('navigation', { name: 'Follow-up views' });
        assert((await nav.getByRole('link', { name: /Overdue/ }).getAttribute('aria-current')) === 'page', 'active tab');
        await nav.getByRole('link', { name: /Completed/ }).click();
        await agent.page.getByText('Outcome: Quote accepted').waitFor();
        await agent.page.screenshot({ path: path.join(SHOTS, 'crm-tasks.png'), fullPage: true });
    });

    // =========================================================== sales manager
    const manager = await session(browser, 'manager', 'manager@shvet.test');

    await check('manager sees every lead, can assign an unassigned lead, and the agent then sees it', async () => {
        await manager.page.goto('/leads');
        const total = await manager.page.locator('tbody tr').count();
        assert(total >= 12, `manager should see all leads, saw ${total}`);
        await manager.page.goto(`/leads/${leadId('Neha Gupta')}`);
        await act(manager.page, () => manager.page.getByLabel('Owner').selectOption({ label: 'Shvet Agent' }), 'PATCH', '/owner');
        assert(sql(`select (select email from users where id=owner_id) from leads where full_name='Neha Gupta'`) === 'agent@shvet.test', 'owner not changed');
        assert(count(`select count(*) from audit_logs where action='lead.assigned'`) >= 1, 'assignment not audited');
        const res = await agent.page.goto(`/leads/${leadId('Neha Gupta')}`);
        assert(res.status() === 200, `agent should now see the lead, got ${res.status()}`);
    });

    await check('reassigning away makes the lead disappear for the previous owner immediately', async () => {
        await manager.page.goto(`/leads/${leadId('Neha Gupta')}`);
        await act(manager.page, () => manager.page.getByLabel('Owner').selectOption({ label: 'Shvet Manager' }), 'PATCH', '/owner');
        const res = await agent.page.goto(`/leads/${leadId('Neha Gupta')}`);
        assert(res.status() === 404, `previous owner should get 404, got ${res.status()}`);
    });

    await check('archive asks for confirmation, hides the lead from lists and the board, and restore brings it back', async () => {
        const id = leadId('Vikram Joshi');
        await manager.page.goto(`/leads/${id}`);
        manager.page.once('dialog', (d) => d.dismiss());
        await manager.page.getByRole('button', { name: 'Archive' }).click();
        await manager.page.waitForTimeout(400);
        assert(sql(`select archived_at is null from leads where public_id='${id}'`) === '1', 'archived despite cancel');

        manager.page.once('dialog', (d) => d.accept());
        await act(manager.page, () => manager.page.getByRole('button', { name: 'Archive' }).click(), 'POST', '/archive');
        await manager.page.waitForURL('**/leads');
        assert((await manager.page.getByText('Vikram Joshi').count()) === 0, 'still listed');
        await manager.page.goto('/pipeline');
        assert((await manager.page.getByText('Vikram Joshi').count()) === 0, 'still on the board');

        await manager.page.goto('/leads');
        await manager.page.getByRole('button', { name: 'Show archived' }).click();
        await manager.page.getByRole('link', { name: 'Vikram Joshi' }).click();
        await manager.page.getByText(/is read-only/).waitFor();
        assert((await manager.page.getByLabel('Stage').isDisabled()), 'archived lead stage must be locked');
        await act(manager.page, () => manager.page.getByRole('button', { name: 'Restore' }).click(), 'POST', '/restore');
        assert(sql(`select archived_at is null from leads where public_id='${id}'`) === '1', 'not restored');
    });

    await check('manager dashboard: all-workspace numbers and the unassigned counter', async () => {
        await manager.page.goto('/dashboard');
        const total = Number(await manager.page.getByText('Total leads', { exact: true }).locator('xpath=following-sibling::p').innerText());
        assert(total === count(`select count(*) from leads where archived_at is null and tenant_id=(select id from tenants where slug='shvet')`), `dashboard ${total} vs db`);
        assert((await manager.page.getByText('Unassigned leads').count()) === 1, 'unassigned counter for managers');
        await manager.page.screenshot({ path: path.join(SHOTS, 'crm-manager-dashboard.png'), fullPage: true });
    });

    // =========================================================== tenant isolation in the browser
    await check('another workspace\'s admin sees only their own data and gets 404 for foreign lead URLs', async () => {
        const acme = await session(browser, 'acme-admin', 'admin@acme.test');
        await acme.page.goto('/leads');
        const names = await acme.page.locator('tbody tr td:first-child a').allInnerTexts();
        assert(JSON.stringify(names) === JSON.stringify(['Acme Only Lead']), `acme sees ${names}`);
        for (const name of ['Priya Nair', 'Deepa Menon']) {
            const res = await acme.page.goto(`/leads/${leadId(name)}`);
            assert(res.status() === 404, `${name} must be 404 for another tenant, got ${res.status()}`);
        }
        await acme.page.goto('/leads?q=priya');
        assert((await acme.page.getByText('No leads match these filters.').count()) === 1, 'search leaked');
        await acme.context.close();
    });

    await check('mobile: the pipeline scrolls horizontally inside its own container without breaking the page', async () => {
        const m = await session(browser, 'mobile', 'agent@shvet.test', { viewport: { width: 375, height: 800 } });
        await m.page.goto('/pipeline');
        await m.page.waitForSelector('[aria-label="Pipeline stages"]');
        const info = await m.page.evaluate(() => {
            const board = document.querySelector('[aria-label="Pipeline stages"]');
            return { boardScrolls: board.scrollWidth > board.clientWidth, pageOverflows: document.documentElement.scrollWidth > window.innerWidth };
        });
        assert(info.boardScrolls && !info.pageOverflows, JSON.stringify(info));
        await m.page.goto('/leads');
        assert(!(await m.page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth)), 'lead list overflows the page on mobile');
        await m.page.screenshot({ path: path.join(SHOTS, 'crm-mobile-leads.png') });
        await m.context.close();
    });

    await check('no unexpected console errors, page errors or CSP violations in the whole run', async () => {
        const real = [...new Set(problems)].filter((p) => !/Failed to load resource: the server responded with a status of (4\d\d)/.test(p));
        assert(real.length === 0, `${real.length} problem(s): ${real.slice(0, 3).join(' || ')}`);
    });

    await browser.close();
    const failed = results.filter((r) => !r.ok);
    console.log(`\n${results.length - failed.length}/${results.length} CRM browser checks passed`);
    fs.writeFileSync(path.join(SHOTS, 'crm-results.json'), JSON.stringify({ results, problems: [...new Set(problems)] }, null, 2));
    process.exit(failed.length ? 1 : 0);
})();
