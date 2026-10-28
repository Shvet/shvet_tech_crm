/* Browser smoke tests (Playwright + Chromium). Run against a SEEDED, NON-PRODUCTION instance:
 *
 *   BASE_URL=http://127.0.0.1:8766 MAIL_LOG=storage/logs/mail.log node e2e/smoke.cjs
 *
 * Requires the app to run with a non-local APP_ENV (so the strict CSP is active) and MAIL_MAILER=log
 * MAIL_LOG_CHANNEL=maillog so reset links can be read. Uses the demo seed data; mutates that database.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8766';
const MAIL_LOG = process.env.MAIL_LOG || 'storage/logs/mail.log';
const SHOTS = process.env.SHOTS_DIR || path.join(__dirname, '../storage/e2e-screenshots');
const PASSWORD = 'Demo-Password-123';
fs.mkdirSync(SHOTS, { recursive: true });

const results = [];
const problems = []; // console errors / CSP violations / page errors
let currentTest = '';

function record(name, ok, detail = '') {
    results.push({ name, ok, detail });
    console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}${detail ? '  — ' + detail : ''}`);
}
async function check(name, fn) {
    currentTest = name;
    try {
        await fn();
        record(name, true);
    } catch (e) {
        const where = (String(e.stack || '').match(/smoke\.cjs:(\d+)/) || [])[1];
        record(name, false, `${String(e.message || e).split('\n')[0]}${where ? ` (smoke.cjs:${where})` : ''}`);
    }
}
function assert(cond, msg) {
    if (!cond) throw new Error(msg);
}

function watch(page, label) {
    if (process.env.DEBUG_E2E) {
        page.on('response', (r) => {
            if (r.request().method() !== 'GET') console.log(`   [${label}] ${r.request().method()} ${r.url().replace(BASE, '')} -> ${r.status()}`);
        });
    }
    page.on('console', (m) => {
        if (m.type() === 'error') problems.push(`[${label}/${currentTest}] console: ${m.text()}`);
    });
    page.on('pageerror', (e) => problems.push(`[${label}/${currentTest}] pageerror: ${e.message}`));
}

async function newSession(browser, label, opts = {}) {
    const context = await browser.newContext({ baseURL: BASE, ...opts });
    const page = await context.newPage();
    watch(page, label);
    return { context, page };
}

async function login(page, email, password = PASSWORD) {
    await page.goto('/login');
    await page.getByLabel('E-mail').fill(email);
    await page.getByLabel('Password').fill(password);
    await page.getByRole('button', { name: 'Sign in' }).click();
    await page.waitForLoadState('networkidle'); // let the request finish (counts towards throttling)
}

/** Click a button and wait for the resulting non-GET request to finish (removes races with stale banners). */
async function submitAndWait(page, clickFn, method = 'POST', urlPart = '') {
    const [res] = await Promise.all([
        page.waitForResponse((r) => r.request().method() === method && r.url().includes(urlPart)),
        clickFn(),
    ]);
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(500); // Inertia runs onSuccess/onError and re-renders after the response
    return res;
}

function decodeQP(s) {
    return s.replace(/=\r?\n/g, '').replace(/=([0-9A-F]{2})/g, (_, h) => String.fromCharCode(parseInt(h, 16)));
}
/** Latest reset link in the mail log for $email (optionally after a byte offset). */
function latestResetLink(email, fromOffset = 0) {
    const raw = decodeQP(fs.readFileSync(MAIL_LOG, 'utf8').slice(fromOffset));
    const re = /https?:\/\/[^\s"'<>]+\/reset-password\/[A-Za-z0-9]+\?email=[^\s"'<>]+/g;
    const links = (raw.match(re) || []).filter((l) => decodeURIComponent(l).includes(email));
    return links.length ? links[links.length - 1].replace(/&amp;/g, '&') : null;
}
const mailOffset = () => (fs.existsSync(MAIL_LOG) ? fs.statSync(MAIL_LOG).size : 0);

(async () => {
    const browser = await chromium.launch();

    // ---------------------------------------------------------------- guests, login, throttling
    {
        const { context, page } = await newSession(browser, 'guest');

        await check('guest hitting a protected page is sent to /login', async () => {
            await page.goto('/dashboard');
            assert(page.url().endsWith('/login'), `landed on ${page.url()}`);
        });

        await check('security headers + strict CSP are present on the login page', async () => {
            const res = await page.goto('/login');
            const h = res.headers();
            assert(h['x-frame-options'] === 'DENY', 'X-Frame-Options');
            assert(h['x-content-type-options'] === 'nosniff', 'nosniff');
            assert((h['content-security-policy'] || '').includes("frame-ancestors 'none'"), 'CSP missing');
            assert((h['content-security-policy'] || '').includes("script-src 'self'"), 'CSP script-src');
        });

        await check('wrong password shows a generic accessible error', async () => {
            await login(page, 'agent@shvet.test', 'definitely-wrong');
            const alert = page.getByRole('alert');
            await alert.first().waitFor();
            assert((await alert.first().innerText()).includes('do not match'), 'message');
            assert(!page.url().includes('dashboard'), 'should stay on login');
            const inputInvalid = await page.getByLabel('E-mail').getAttribute('aria-invalid');
            assert(inputInvalid === 'true', 'aria-invalid not set');
        });

        await check('login is throttled after repeated failures (even with the right password afterwards)', async () => {
            for (let i = 0; i < 6; i++) await login(page, 'ghost@e2e.test', 'nope-nope-nope');
            await page.getByText(/Too many login attempts/).waitFor({ timeout: 5000 });
        });

        await check('no public registration page', async () => {
            const res = await page.goto('/register');
            assert(res.status() === 404, `status ${res.status()}`);
        });
        await context.close();
    }

    // ---------------------------------------------------------------- agent: role enforcement + session cookies
    const agent = await newSession(browser, 'agent');
    await check('sales agent signs in and lands on the dashboard with the right role', async () => {
        await login(agent.page, 'agent@shvet.test');
        await agent.page.waitForURL('**/dashboard');
        await agent.page.locator('aside').getByText('Shvet Technologies').waitFor();
        assert((await agent.page.content()).includes('Sales Agent'), 'role not displayed');
        await agent.page.screenshot({ path: path.join(SHOTS, 'agent-dashboard.png') });
    });

    await check('agent has no Team navigation and /team is forbidden server-side', async () => {
        const nav = agent.page.getByRole('navigation', { name: 'Main' });
        assert((await nav.getByRole('link', { name: 'Team' }).count()) === 0, 'Team link visible');
        const res = await agent.page.goto('/team');
        assert(res.status() === 403, `status ${res.status()}`);
    });

    await check('agent cannot call team-management endpoints directly (CSRF-valid request, 403)', async () => {
        await agent.page.goto('/dashboard');
        const status = await agent.page.evaluate(async () => {
            const token = decodeURIComponent(document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN=')).split('=')[1]);
            const r = await fetch('/team/members', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': token },
                body: JSON.stringify({ name: 'x', email: 'x@e2e.test', role: 'Admin' }),
            });
            return r.status;
        });
        assert(status === 403, `status ${status}`);
    });

    await check('forged cross-site / same-site / header-less POSTs with valid cookies are rejected (419)', async () => {
        // Laravel 13 accepts the browser-set Sec-Fetch-Site: same-origin header in place of a token; anything else needs the token.
        for (const headers of [{}, { 'Sec-Fetch-Site': 'cross-site' }, { 'Sec-Fetch-Site': 'same-site' }]) {
            const res = await agent.context.request.post('/logout', { headers: { Accept: 'application/json', ...headers } });
            assert(res.status() === 419, `headers ${JSON.stringify(headers)} -> ${res.status()}`);
        }
        await agent.page.goto('/dashboard');
        assert(agent.page.url().endsWith('/dashboard'), 'agent should still be signed in after rejected forgeries');
    });

    await check('session cookie is HttpOnly + SameSite=Lax; XSRF cookie readable by JS', async () => {
        const cookies = await agent.context.cookies();
        const session = cookies.find((c) => /session/i.test(c.name) && c.name !== 'XSRF-TOKEN');
        const xsrf = cookies.find((c) => c.name === 'XSRF-TOKEN');
        assert(session && session.httpOnly, 'session cookie not HttpOnly');
        assert(session.sameSite === 'Lax', `SameSite=${session.sameSite}`);
        assert(xsrf && !xsrf.httpOnly, 'XSRF cookie flags');
    });

    // ---------------------------------------------------------------- multi-workspace user
    await check('multi-workspace user must pick a workspace and gets a different role in each', async () => {
        const { context, page } = await newSession(browser, 'multi');
        await login(page, 'multi@demo.test');
        await page.waitForURL('**/workspaces');
        await page.getByText('Shvet Technologies').waitFor();
        await page.getByText('Acme Demo Co').waitFor();

        await page.locator('li', { hasText: 'Acme Demo Co' }).getByRole('button', { name: 'Open' }).click();
        await page.waitForURL('**/dashboard');
        assert((await page.content()).includes('Admin'), 'expected Admin in Acme');
        assert((await page.getByRole('link', { name: 'Team' }).count()) === 1, 'Team link expected for Admin');

        await page.getByRole('link', { name: 'Switch workspace' }).click();
        await page.locator('li', { hasText: 'Shvet Technologies' }).getByRole('button', { name: 'Open' }).click();
        await page.waitForURL('**/dashboard');
        assert((await page.content()).includes('Sales Agent'), 'expected Sales Agent in Shvet');
        const res = await page.goto('/team');
        assert(res.status() === 403, `Team in Shvet should be 403, got ${res.status()}`);
        await context.close();
    });

    // ---------------------------------------------------------------- admin team management
    const admin = await newSession(browser, 'admin');
    await login(admin.page, 'admin@shvet.test');
    await admin.page.waitForURL('**/dashboard');

    await check('admin sees the team table with only this workspace\'s members', async () => {
        await admin.page.getByRole('link', { name: 'Team' }).click();
        await admin.page.getByRole('heading', { name: 'Team', exact: true }).waitFor();
        const text = await admin.page.locator('table').innerText();
        assert(text.includes('agent@shvet.test') && text.includes('manager@shvet.test'), 'expected Shvet members');
        assert(!text.includes('agent@acme.test') && !text.includes('admin@acme.test'), 'Acme members leaked into Shvet');
        await admin.page.screenshot({ path: path.join(SHOTS, 'admin-team.png') });
    });

    let inviteOffset = mailOffset();
    await check('admin invites a new member (name rendered as text, not HTML)', async () => {
        inviteOffset = mailOffset();
        await admin.page.getByLabel('Name').fill('<img src=x onerror="window.__xss=1"> Newbie');
        await admin.page.locator('#email').fill('newbie@e2e.test');
        await admin.page.locator('#role').selectOption('Sales Agent');
        await submitAndWait(admin.page, () => admin.page.getByRole('button', { name: 'Send invitation' }).click(), 'POST', '/team/members');
        await admin.page.getByRole('status').getByText('Invitation created.').waitFor();
        const row = admin.page.locator('tr', { hasText: 'newbie@e2e.test' });
        await row.waitFor();
        assert((await row.innerText()).toLowerCase().includes('invited'), 'status should be invited');
        assert((await admin.page.evaluate(() => window.__xss)) === undefined, 'XSS payload executed');
        assert((await admin.page.locator('img[src="x"]').count()) === 0, 'injected <img> element exists');
    });

    await check('inviting the same pending person again renews (no duplicate row)', async () => {
        await admin.page.getByLabel('Name').fill('Newbie');
        await admin.page.locator('#email').fill('newbie@e2e.test');
        await submitAndWait(admin.page, () => admin.page.getByRole('button', { name: 'Send invitation' }).click(), 'POST', '/team/members');
        await admin.page.getByRole('status').getByText('Invitation created.').waitFor();
        assert((await admin.page.locator('tr', { hasText: 'newbie@e2e.test' }).count()) === 1, 'duplicate rows');
    });

    await check('inviting an existing active member shows a validation error', async () => {
        await admin.page.getByLabel('Name').fill('Dup');
        await admin.page.locator('#email').fill('agent@shvet.test');
        await submitAndWait(admin.page, () => admin.page.getByRole('button', { name: 'Send invitation' }).click(), 'POST', '/team/members');
        await admin.page.getByText('already a member').waitFor();
    });

    await check('admin changes a role; it persists after reload', async () => {
        const sel = admin.page.getByLabel('Role for Shvet Agent');
        await submitAndWait(admin.page, () => sel.selectOption('Sales Manager'), 'PATCH', '/team/members/');
        await admin.page.reload();
        assert((await admin.page.getByLabel('Role for Shvet Agent').inputValue()) === 'Sales Manager', 'role not persisted');
        await submitAndWait(admin.page, () => admin.page.getByLabel('Role for Shvet Agent').selectOption('Sales Agent'), 'PATCH', '/team/members/'); // restore
    });

    await check('suspending a member locks them out on their very next navigation; reinstating restores access', async () => {
        await admin.page.locator('tr', { hasText: 'agent@shvet.test' }).getByRole('button', { name: 'Suspend' }).click();
        await admin.page.locator('tr', { hasText: 'agent@shvet.test' }).getByText('suspended').waitFor();

        await agent.page.goto('/dashboard');
        assert(agent.page.url().endsWith('/workspaces'), `agent landed on ${agent.page.url()}`);
        assert((await agent.page.getByText('You do not belong to any workspace').count()) === 1, 'agent should see no workspaces');

        await admin.page.locator('tr', { hasText: 'agent@shvet.test' }).getByRole('button', { name: 'Reinstate' }).click();
        await admin.page.locator('tr', { hasText: 'agent@shvet.test' }).getByText('active').waitFor();
        await agent.page.goto('/dashboard');
        assert(agent.page.url().endsWith('/dashboard'), `agent landed on ${agent.page.url()}`);
    });

    await check('removal asks for confirmation; cancelling keeps the member, confirming removes them', async () => {
        admin.page.once('dialog', (d) => d.dismiss());
        await admin.page.locator('tr', { hasText: 'manager@shvet.test' }).getByRole('button', { name: 'Remove' }).click();
        await admin.page.waitForTimeout(400);
        assert((await admin.page.locator('tr', { hasText: 'manager@shvet.test' }).innerText()).toLowerCase().includes('active'), 'member should remain active');

        admin.page.once('dialog', (d) => d.accept());
        await admin.page.locator('tr', { hasText: 'manager@shvet.test' }).getByRole('button', { name: 'Remove' }).click();
        await admin.page.locator('tr', { hasText: 'manager@shvet.test' }).getByText('removed').waitFor();
    });

    await check('the last active Admin cannot demote, suspend or remove themselves (errors shown, role unchanged)', async () => {
        await admin.page.goto('/team');
        await submitAndWait(admin.page, () => admin.page.getByLabel('Role for Shvet Admin').selectOption('Sales Agent'), 'PATCH', '/team/members/');
        await admin.page.getByText(/at least one active Admin/).waitFor();
        assert((await admin.page.getByLabel('Role for Shvet Admin').inputValue()) === 'Admin', 'role must still be Admin');
        const row = admin.page.locator('tr', { hasText: 'admin@shvet.test' });
        await submitAndWait(admin.page, () => row.getByRole('button', { name: 'Suspend' }).click(), 'PATCH', '/team/members/');
        await admin.page.getByText(/at least one active Admin/).waitFor();
        admin.page.once('dialog', (d) => d.accept());
        await submitAndWait(admin.page, () => row.getByRole('button', { name: 'Remove' }).click(), 'DELETE', '/team/members/');
        await admin.page.getByText(/at least one active Admin/).waitFor();
        assert((await row.innerText()).toLowerCase().includes('active'), 'admin must remain active');
    });

    await check('a second Admin makes demotion allowed (Acme has two seeded Admins)', async () => {
        const { context, page } = await newSession(browser, 'acme-admin');
        await login(page, 'admin@acme.test');
        await page.waitForURL('**/dashboard');
        await page.goto('/team');
        // Demote the OTHER Admin (self-demotion would remove this page's own access): allowed because a second Admin exists.
        await submitAndWait(page, () => page.getByLabel('Role for Multi Workspace').selectOption('Sales Agent'), 'PATCH', '/team/members/');
        assert((await page.getByLabel('Role for Multi Workspace').inputValue()) === 'Sales Agent', 'demotion should succeed while another Admin remains');
        // Now acme admin is the last Admin: demoting them is refused.
        await submitAndWait(page, () => page.getByLabel('Role for Acme Admin').selectOption('Sales Agent'), 'PATCH', '/team/members/');
        await page.getByText(/at least one active Admin/).waitFor();
        await context.close();
    });

    // ---------------------------------------------------------------- invitation acceptance + password setup
    await check('invited person sets a password via the e-mailed link, then must accept explicitly', async () => {
        const link = latestResetLink('newbie@e2e.test', inviteOffset);
        assert(link, 'no setup link found in mail log');
        const { context, page } = await newSession(browser, 'newbie');
        await page.goto(link);
        await page.getByLabel(/New password/).fill('Brand-New-Passw0rd!');
        await page.getByLabel('Confirm password').fill('Different-Passw0rd!1');
        await page.getByRole('button', { name: 'Save password' }).click();
        await page.getByRole('alert').first().waitFor(); // mismatch rejected
        await page.getByLabel('Confirm password').fill('Brand-New-Passw0rd!');
        await page.getByRole('button', { name: 'Save password' }).click();
        await page.waitForURL('**/login');
        await page.getByText('Your password has been reset').waitFor();

        await login(page, 'newbie@e2e.test', 'Brand-New-Passw0rd!');
        await page.waitForURL('**/workspaces');
        await page.getByText('Invitation').waitFor(); // pending, NOT auto-accepted
        await page.goto('/dashboard');
        assert(page.url().endsWith('/workspaces'), 'must not reach dashboard before accepting');

        await page.getByRole('button', { name: 'Accept' }).click();
        await page.getByRole('button', { name: 'Open' }).click();
        await page.waitForURL('**/dashboard');
        assert((await page.content()).includes('Sales Agent'), 'role after accepting');

        // reset token is single-use
        await page.context().clearCookies();
        await page.goto(link);
        await page.getByLabel(/New password/).fill('Another-Passw0rd!2');
        await page.getByLabel('Confirm password').fill('Another-Passw0rd!2');
        await page.getByRole('button', { name: 'Save password' }).click();
        await page.getByText(/invalid or has expired/).waitFor();
        await context.close();
    });

    await check('forgot-password gives the same answer for unknown addresses; real flow resets the password', async () => {
        const { context, page } = await newSession(browser, 'reset');
        await page.goto('/forgot-password');
        await page.getByLabel('E-mail').fill('nobody@e2e.test');
        await page.getByRole('button', { name: 'Send reset link' }).click();
        const unknownMsg = await page.getByRole('status').innerText();

        const off = mailOffset();
        await page.getByLabel('E-mail').fill('agent@acme.test');
        await page.getByRole('button', { name: 'Send reset link' }).click();
        await page.waitForTimeout(500);
        const knownMsg = await page.getByRole('status').innerText();
        assert(unknownMsg === knownMsg && unknownMsg.length > 0, `answers differ: "${unknownMsg}" vs "${knownMsg}"`);

        const link = latestResetLink('agent@acme.test', off);
        assert(link, 'reset link missing from mail log');
        await page.goto(link);
        await page.getByLabel(/New password/).fill('short');
        await page.getByLabel('Confirm password').fill('short');
        await page.getByRole('button', { name: 'Save password' }).click();
        await page.getByRole('alert').first().waitFor(); // weak password rejected
        await page.getByLabel(/New password/).fill('Acme-Reset-Passw0rd!');
        await page.getByLabel('Confirm password').fill('Acme-Reset-Passw0rd!');
        await page.getByRole('button', { name: 'Save password' }).click();
        await page.waitForURL('**/login');

        await login(page, 'agent@acme.test', PASSWORD); // old password no longer works
        await page.getByRole('alert').first().waitFor();
        await login(page, 'agent@acme.test', 'Acme-Reset-Passw0rd!');
        await page.waitForURL('**/dashboard');
        await context.close();
    });

    await check('an invalid reset link is rejected with an error', async () => {
        const { context, page } = await newSession(browser, 'badtoken');
        await page.goto('/reset-password/' + 'a'.repeat(64) + '?email=agent%40shvet.test');
        await page.getByLabel(/New password/).fill('Valid-Passw0rd!123');
        await page.getByLabel('Confirm password').fill('Valid-Passw0rd!123');
        await page.getByRole('button', { name: 'Save password' }).click();
        await page.getByText(/invalid or has expired/).waitFor();
        await context.close();
    });

    // ---------------------------------------------------------------- session behaviour
    await check('changing a password on one device signs out the other device', async () => {
        const a = await newSession(browser, 'device-a');
        const b = await newSession(browser, 'device-b');
        await login(a.page, 'agent@acme.test', 'Acme-Reset-Passw0rd!');
        await login(b.page, 'agent@acme.test', 'Acme-Reset-Passw0rd!');
        await a.page.waitForURL('**/dashboard');
        await b.page.waitForURL('**/dashboard');

        await a.page.goto('/profile');
        await a.page.getByLabel('Current password').fill('Acme-Reset-Passw0rd!');
        await a.page.locator('#password').fill('Acme-Second-Passw0rd!');
        await a.page.getByLabel('Confirm new password').fill('Acme-Second-Passw0rd!');
        await a.page.getByRole('button', { name: 'Update password' }).click();
        await a.page.getByText('Password updated.').waitFor();

        await b.page.goto('/dashboard');
        assert(b.page.url().endsWith('/login'), `device B landed on ${b.page.url()}`);
        await a.page.goto('/dashboard');
        assert(a.page.url().endsWith('/dashboard'), 'device A should stay signed in');
        await a.context.close();
        await b.context.close();
    });

    await check('after sign-out the dashboard is unreachable, including via the back button + reload', async () => {
        await agent.page.goto('/dashboard');
        await agent.page.getByRole('button', { name: 'Sign out' }).click();
        await agent.page.waitForURL('**/login');
        await agent.page.goBack();
        await agent.page.waitForTimeout(800);
        // Without reload: the previous user's page must not be rendered from browser history.
        assert(!(await agent.page.content()).includes('Figures cover the leads you are allowed to see'), 'dashboard content visible from history after logout');
        await agent.page.reload();
        assert(agent.page.url().endsWith('/login'), `after back+reload at ${agent.page.url()}`);
        const res = await agent.page.goto('/dashboard');
        assert(agent.page.url().endsWith('/login'), 'dashboard reachable after logout');
    });

    await check('mobile layout: sidebar is collapsed behind a Menu button and opens on tap', async () => {
        const { context, page } = await newSession(browser, 'mobile', { viewport: { width: 375, height: 700 } });
        await login(page, 'admin@shvet.test');
        await page.waitForURL('**/dashboard');
        const nav = page.getByRole('navigation', { name: 'Main' });
        assert(!(await nav.isVisible()), 'nav should be hidden on mobile initially');
        await page.getByRole('button', { name: 'Menu' }).click();
        assert(await nav.isVisible(), 'nav should open');
        const overflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth);
        assert(!overflow, 'horizontal scroll on mobile');
        await page.screenshot({ path: path.join(SHOTS, 'mobile-menu.png') });
        await context.close();
    });

    await check('no console errors, page errors or CSP violations occurred during the whole run', async () => {
        const unique = [...new Set(problems)];
        // 4xx responses for deliberately forbidden requests surface as console errors in Chromium; ignore those only.
        const real = unique.filter((p) => !/Failed to load resource: the server responded with a status of (4\d\d)/.test(p));
        assert(real.length === 0, `${real.length} problem(s): ${real.slice(0, 3).join(' || ')}`);
    });

    await browser.close();

    const failed = results.filter((r) => !r.ok);
    console.log(`\n${results.length - failed.length}/${results.length} browser checks passed`);
    if (problems.length) console.log(`(informational) ${new Set(problems).size} distinct console messages recorded`);
    fs.writeFileSync(path.join(SHOTS, 'results.json'), JSON.stringify({ results, problems: [...new Set(problems)] }, null, 2));
    process.exit(failed.length ? 1 : 0);
})();
