/* Browser tests for the WhatsApp inbox (Playwright + Chromium) against a MOCK Graph server.
 *
 * EVERYTHING HERE IS MOCKED: it proves our webhook, inbox and send code work end to end under our assumptions about the
 * Cloud API; it proves nothing about Meta itself. Run through e2e/run.sh (it starts the mock and sets the env):
 *
 *   bash e2e/run.sh whatsapp
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
const PHONE_ID = '110000000000001';
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
        const where = (String(e.stack || '').match(/whatsapp\.cjs:(\d+)/) || [])[1];
        record(name, false, `${String(e.message || e).split('\n')[0]}${where ? ` (whatsapp.cjs:${where})` : ''}`);
    }
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

/** An inbound WhatsApp message, signed exactly as Meta would sign it. */
async function inbound(wamid, from, text, name = 'Ravi Kumar', { signature } = {}) {
    const body = JSON.stringify({
        object: 'whatsapp_business_account',
        entry: [{ id: '220000000000001', changes: [{ field: 'messages', value: {
            messaging_product: 'whatsapp',
            metadata: { display_phone_number: '912240000000', phone_number_id: PHONE_ID },
            contacts: [{ profile: { name }, wa_id: from }],
            messages: [{ from, id: wamid, timestamp: String(Math.floor(Date.now() / 1000)), type: 'text', text: { body: text } }],
        } }] }],
    });
    const sig = signature ?? 'sha256=' + crypto.createHmac('sha256', SECRET).update(body).digest('hex');
    const res = await fetch(`${BASE}/webhooks/whatsapp`, { method: 'POST', body, headers: { 'Content-Type': 'application/json', 'X-Hub-Signature-256': sig } });
    return res.status;
}

(async () => {
    const browser = await chromium.launch();
    await fetch(`${MOCK}/__mock/reset`);

    const admin = await session(browser, 'admin', 'admin@shvet.test');

    await check('admin connects the WhatsApp number; the token is never shown again', async () => {
        const p = admin.page;
        await p.goto('/integrations/whatsapp');
        await p.getByLabel('WhatsApp Business Account id').fill('220000000000001');
        await p.getByLabel('Phone number id').fill(PHONE_ID);
        await p.getByLabel('System User access token').fill('EAAG-e2e-system-user-token-000000');
        await p.getByRole('button', { name: 'Connect' }).click();
        await p.getByText('WhatsApp number connected.').waitFor();
        await p.getByText('Mock Realty', { exact: false }).first().waitFor();
        await p.getByText('follow_up').waitFor();
        assert(!(await p.content()).includes('EAAG-e2e'), 'token rendered in the page');
        assert(sql(`select status from whatsapp_accounts`) === 'active', 'account not active');
    });

    await check('a bad webhook signature is rejected; a signed message lands in the inbox as a new lead', async () => {
        assert((await inbound('wamid.e2e0000000001', '919812300001', 'Hi', 'X', { signature: 'sha256=' + '0'.repeat(64) })) === 403, 'bad signature accepted');
        assert((await inbound('wamid.e2e0000000002', '919812300001', 'Is the 2BHK still available?')) === 200, 'webhook refused');
        assert((await inbound('wamid.e2e0000000002', '919812300001', 'Is the 2BHK still available?')) === 200, 'redelivery refused');
        assert(count(`select count(*) from whatsapp_messages`) === 1, 'redelivery stored twice');
        assert(count(`select count(*) from leads where external_provider='whatsapp' and phone_e164='+919812300001'`) === 1, 'lead not created');

        const p = admin.page;
        await p.goto('/inbox');
        await p.getByRole('link', { name: /Ravi Kumar/ }).click();
        await p.getByText('Is the 2BHK still available?').waitFor();
    });

    await check('admin replies; the mock Cloud API receives it and the message shows as sent', async () => {
        const p = admin.page;
        await p.getByLabel('Message', { exact: true }).fill('Yes! When can we call you?');
        await p.getByRole('button', { name: 'Send' }).click();
        await p.getByRole('list', { name: 'Messages' }).getByText('Yes! When can we call you?').waitFor();
        const sent = await (await fetch(`${MOCK}/__mock/sent`)).json();
        assert(sent.length === 1 && sent[0].to === '919812300001' && sent[0].text.body === 'Yes! When can we call you?', 'mock did not receive the reply: ' + JSON.stringify(sent));
        await p.reload();
        await p.getByText(/· Sent/).first().waitFor();
    });

    await check('a delivery status update from Meta moves the message to Read', async () => {
        const wamid = sql(`select wamid from whatsapp_messages where direction='out' limit 1`);
        const body = JSON.stringify({ object: 'whatsapp_business_account', entry: [{ id: '220000000000001', changes: [{ field: 'messages', value: {
            messaging_product: 'whatsapp', metadata: { phone_number_id: PHONE_ID }, statuses: [{ id: wamid, status: 'read', timestamp: String(Math.floor(Date.now() / 1000)), recipient_id: '919812300001' }],
        } }] }] });
        const sig = 'sha256=' + crypto.createHmac('sha256', SECRET).update(body).digest('hex');
        await fetch(`${BASE}/webhooks/whatsapp`, { method: 'POST', body, headers: { 'Content-Type': 'application/json', 'X-Hub-Signature-256': sig } });
        await admin.page.reload();
        await admin.page.getByText(/· Read/).first().waitFor();
    });

    await check('after 24 hours only a template can be sent, with its placeholders filled in', async () => {
        sql(`update whatsapp_conversations set last_inbound_at = now() - interval 2 day`);
        const p = admin.page;
        await p.reload();
        await p.getByText(/only an approved template/).waitFor();
        assert((await p.getByLabel('Message', { exact: true }).count()) === 0, 'free text offered outside the window');
        await p.getByLabel('Template').selectOption({ label: 'follow_up (en)' });
        await p.getByLabel('Value for {{1}}').fill('Ravi');
        await p.getByLabel('Value for {{2}}').fill('the 2BHK');
        await p.getByRole('button', { name: 'Send' }).click();
        await p.getByRole('list', { name: 'Messages' }).getByText('Hi Ravi, thanks for asking about the 2BHK.').waitFor();
        const sent = await (await fetch(`${MOCK}/__mock/sent`)).json();
        assert(sent.at(-1).type === 'template' && sent.at(-1).template.name === 'follow_up', 'template not sent');
    });

    await check('agents only see chats for their own leads; other chats are 404', async () => {
        const convo = sql(`select public_id from whatsapp_conversations limit 1`);
        const agent = await session(browser, 'agent', 'agent@shvet.test');
        await agent.page.goto('/inbox');
        assert((await agent.page.getByRole('link', { name: /Ravi Kumar/ }).count()) === 0, 'agent sees an unassigned lead chat');
        const res = await agent.page.goto(`/inbox/${convo}`);
        assert(res.status() === 404, `expected 404 got ${res.status()}`);

        const agentId = sql(`select id from users where email='agent@shvet.test'`);
        sql(`update leads set owner_id=${agentId} where external_provider='whatsapp'`);
        await agent.page.goto('/inbox');
        await agent.page.getByRole('link', { name: /Ravi Kumar/ }).click();
        await agent.page.getByText('Is the 2BHK still available?').waitFor();
        await agent.context.close();
    });

    await check('STOP opts the contact out and the composer is locked', async () => {
        assert((await inbound('wamid.e2e0000000003', '919812300001', 'STOP')) === 200, 'webhook refused');
        await admin.page.reload();
        await admin.page.getByText(/opted out/).waitFor();
        assert((await admin.page.getByRole('button', { name: 'Send' }).count()) === 0, 'send still offered');
    });

    await check('the other workspace cannot open this chat', async () => {
        const convo = sql(`select public_id from whatsapp_conversations limit 1`);
        const acme = await session(browser, 'acme', 'admin@acme.test');
        const res = await acme.page.goto(`/inbox/${convo}`);
        assert(res.status() === 404, `expected 404 got ${res.status()}`);
        await acme.context.close();
    });

    await check('mobile layout of the inbox does not overflow', async () => {
        const m = await session(browser, 'mobile', 'admin@shvet.test', { viewport: { width: 375, height: 800 } });
        const convo = sql(`select public_id from whatsapp_conversations limit 1`);
        await m.page.goto(`/inbox/${convo}`);
        await m.page.getByText('Is the 2BHK still available?').waitFor();
        assert(!(await m.page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth)), 'page overflows on mobile');
        await m.page.screenshot({ path: path.join(SHOTS, 'whatsapp-mobile.png'), fullPage: true });
        await m.context.close();
    });

    await admin.page.screenshot({ path: path.join(SHOTS, 'whatsapp-inbox.png'), fullPage: true });

    await check('no unexpected console errors, page errors or CSP violations in the whole run', async () => {
        const real = [...new Set(problems)].filter((x) => !/Failed to load resource: the server responded with a status of (4\d\d)/.test(x));
        assert(real.length === 0, `${real.length} problem(s): ${real.slice(0, 3).join(' || ')}`);
    });

    await browser.close();
    const failed = results.filter((r) => !r.ok);
    console.log(`\n${results.length - failed.length}/${results.length} WhatsApp browser checks passed (MOCK Graph server)`);
    process.exit(failed.length ? 1 : 0);
})();
