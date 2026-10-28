/**
 * MOCK Meta Graph + OAuth dialog server for the browser tests (e2e/meta.cjs). It is NOT Meta and proves nothing about
 * Meta's real behavior; it only lets us drive our own OAuth/webhook/import code end-to-end without any credentials.
 *
 *   node e2e/mock-graph-server.cjs 8799
 */
const http = require('http');
const { URL } = require('url');

const port = Number(process.argv[2] || 8799);
const state = { leads: {}, calls: [] };

const SCOPES = ['pages_show_list', 'pages_read_engagement', 'pages_manage_metadata', 'leads_retrieval', 'pages_manage_ads'];
const PAGE = { id: '100000000000001', name: 'Mock Page', access_token: 'MOCK-PAGE-TOKEN' };

const json = (res, body, status = 200) => {
    res.writeHead(status, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify(body));
};

http.createServer((req, res) => {
    const url = new URL(req.url, `http://127.0.0.1:${port}`);
    const path = url.pathname.replace(/^\/v[\d.]+/, '');
    state.calls.push(`${req.method} ${path}`);

    // Test control plane.
    if (path === '/__mock/lead' && req.method === 'POST') {
        let body = '';
        req.on('data', (c) => (body += c));
        req.on('end', () => {
            const lead = JSON.parse(body);
            state.leads[lead.id] = lead;
            json(res, { ok: true });
        });
        return;
    }
    if (path === '/__mock/calls') return json(res, state.calls);
    if (path === '/__mock/reset') {
        state.leads = {};
        state.calls = [];
        return json(res, { ok: true });
    }

    // The "login dialog": immediately sends the browser back with a code and the state we were given.
    if (path === '/dialog/oauth') {
        const back = new URL(url.searchParams.get('redirect_uri'));
        back.searchParams.set('code', 'MOCK-CODE');
        back.searchParams.set('state', url.searchParams.get('state'));
        res.writeHead(302, { Location: back.toString() });
        return res.end();
    }

    if (path === '/oauth/access_token') return json(res, { access_token: 'MOCK-USER-TOKEN', expires_in: 5000000 });
    if (path === '/debug_token') return json(res, { data: { is_valid: true, scopes: SCOPES, user_id: 'mockuser', expires_at: Math.floor(Date.now() / 1000) + 5000000 } });
    if (path === '/me') return json(res, { id: 'mockuser', name: 'Mock Page Admin' });
    if (path === '/me/accounts') return json(res, { data: [PAGE] });
    if (path.endsWith('/subscribed_apps')) return json(res, { success: true });
    if (path.endsWith('/leadgen_forms')) return json(res, { data: [{ id: '700000000000001', name: 'Mock Diwali form', status: 'ACTIVE' }] });

    const id = path.slice(1);
    if (state.leads[id]) return json(res, state.leads[id]);

    return json(res, { error: { code: 803, message: 'mock: not found' } }, 404);
}).listen(port, '127.0.0.1', () => console.log(`MOCK Graph server on ${port}`));
