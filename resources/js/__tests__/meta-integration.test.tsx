import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import Index from '../pages/Integrations/Meta/Index';
import { visibleNav } from '../lib/permissions';
import { resetInertiaMock, router, setPageProps } from './mocks/inertia';

vi.mock('@inertiajs/react', async () => await import('./mocks/inertia'));

const adminTenant = { id: 't1', name: 'Acme', timezone: 'Asia/Kolkata', currency: 'INR', role: 'Admin', permissions: ['integrations.manage'] };

const base = () => ({
    setup: { configured: true, missing: [] as string[], redirect_uri: 'https://crm.test/integrations/meta/callback', webhook_url: 'https://crm.test/webhooks/meta', permissions: ['leads_retrieval', 'pages_show_list'] },
    connections: [] as any[],
    pages: [] as any[],
    counts: { imported: 3, needs_review: 1, failed: 2, ignored: 0, received: 0, quarantined: 0 } as Record<string, number>,
    events: [] as any[],
    options: { sources: [{ id: 5, name: 'Facebook Ads' }], stages: [{ id: 9, name: 'New' }], members: [{ id: 2, name: 'Asha' }] },
});

const connection = (over: Record<string, unknown> = {}) => ({
    id: 'C1', account_name: 'Page Admin', status: 'active', last_validated_at: null, last_sync_at: null, data_access_expires_at: null, error: null, pending_pages: [], ...over,
});
const form = (over: Record<string, unknown> = {}) => ({
    id: 'F1', form_id: '700', name: 'Diwali form', status: 'ACTIVE', enabled: false, lead_source_id: null, pipeline_stage_id: null, default_owner_id: null, field_map: {}, last_import_at: null, ...over,
});
const page = (over: Record<string, unknown> = {}) => ({
    id: 'P1', connection_id: 'C1', name: 'Acme Page', subscription_status: 'subscribed', subscription_error: null, last_sync_at: null, forms: [form()], ...over,
});
const event = (over: Record<string, unknown> = {}) => ({
    id: 'E1', status: 'failed', outcome: null, received_at: '2026-10-28T10:00:00Z', attempts: 3, platform: 'fb', failure: 'Meta rejected the request', warnings: [], lead_id: null, replayable: true, reviewable: false, ...over,
});

describe('Meta integration page', () => {
    beforeEach(() => {
        resetInertiaMock();
        setPageProps({ tenant: adminTenant });
        vi.spyOn(window, 'confirm').mockReturnValue(true);
    });

    it('is only in the navigation for people who can manage integrations', () => {
        expect(visibleNav(['integrations.manage']).map((i) => i.label)).toContain('Meta Lead Ads');
        expect(visibleNav(['dashboard.view', 'leads.view_all', 'users.view']).map((i) => i.label)).not.toContain('Meta Lead Ads');
    });

    it('refuses to render controls without the permission', () => {
        setPageProps({ tenant: { ...adminTenant, permissions: ['leads.view_own'] } });
        render(<Index {...base()} />);

        expect(screen.getByRole('alert')).toHaveTextContent('do not have access');
        expect(screen.queryByRole('button', { name: /connect/i })).not.toBeInTheDocument();
    });

    it('explains what is missing when the server is not configured and offers no connect button', () => {
        const props = base();
        props.setup = { ...props.setup, configured: false, missing: ['META_APP_ID', 'META_GRAPH_VERSION'] };
        render(<Index {...props} />);

        expect(screen.getByRole('alert')).toHaveTextContent('META_APP_ID, META_GRAPH_VERSION');
        expect(screen.queryByRole('button', { name: 'Connect with Facebook' })).not.toBeInTheDocument();
    });

    it('starts the OAuth flow with a plain POST (no client-supplied tenant or redirect)', async () => {
        render(<Index {...base()} />);

        await userEvent.click(screen.getByRole('button', { name: 'Connect with Facebook' }));

        expect(router.post).toHaveBeenCalledWith('/integrations/meta/connect');
    });

    it('shows the webhook and redirect URLs for the Meta administrator', () => {
        render(<Index {...base()} />);

        expect(screen.getByText('https://crm.test/webhooks/meta')).toBeInTheDocument();
        expect(screen.getByText('https://crm.test/integrations/meta/callback')).toBeInTheDocument();
    });

    it('lets the admin pick pages from the pending list and posts only their ids', async () => {
        const props = base();
        props.connections = [connection({ status: 'pending_selection', pending_pages: [{ id: '111', name: 'Page One' }, { id: '222', name: 'Page Two' }] })];
        render(<Index {...props} />);

        const submit = screen.getByRole('button', { name: 'Connect selected Pages' });
        expect(submit).toBeDisabled();
        await userEvent.click(screen.getByLabelText('Page Two'));
        await userEvent.click(submit);

        expect(router.post).toHaveBeenCalledWith('/integrations/meta/connections/C1/pages', { pages: ['222'] }, expect.anything());
    });

    it('shows a prominent reconnect banner when authorization is required', () => {
        const props = base();
        props.connections = [connection({ status: 'reauthorization_required', error: 'Meta rejected the saved authorization.' })];
        render(<Index {...props} />);

        expect(screen.getByText('Imports are paused for this account.')).toBeInTheDocument();
        expect(screen.getByText(/Meta rejected the saved authorization/)).toBeInTheDocument();
    });

    it('disconnecting asks for confirmation and does nothing when declined', async () => {
        const props = base();
        props.connections = [connection()];
        render(<Index {...props} />);
        vi.mocked(window.confirm).mockReturnValueOnce(false);

        await userEvent.click(screen.getByRole('button', { name: 'Disconnect' }));
        expect(router.delete).not.toHaveBeenCalled();

        await userEvent.click(screen.getByRole('button', { name: 'Disconnect' }));
        expect(window.confirm).toHaveBeenCalledWith(expect.stringContaining('Existing leads are kept'));
        expect(router.delete).toHaveBeenCalledWith('/integrations/meta/connections/C1', expect.anything());
    });

    it('cannot save an enabled form without a source and stage', async () => {
        const props = base();
        props.connections = [connection()];
        props.pages = [page()];
        render(<Index {...props} />);
        const f = within(screen.getByRole('form', { name: 'Form Diwali form' }));

        await userEvent.click(f.getByLabelText('Import leads from this form'));

        expect(f.getByRole('alert')).toHaveTextContent('Choose a lead source and a starting stage');
        expect(f.getByRole('button', { name: 'Save mapping' })).toBeDisabled();
    });

    it('saves a complete mapping with numeric ids and the custom question keys', async () => {
        const props = base();
        props.connections = [connection()];
        props.pages = [page()];
        render(<Index {...props} />);
        const f = within(screen.getByRole('form', { name: 'Form Diwali form' }));

        await userEvent.click(f.getByLabelText('Import leads from this form'));
        await userEvent.selectOptions(f.getByLabelText('Lead source'), '5');
        await userEvent.selectOptions(f.getByLabelText('Starting stage'), '9');
        await userEvent.selectOptions(f.getByLabelText('Default owner'), '2');
        await userEvent.click(f.getByText('Custom question keys (optional)'));
        await userEvent.type(f.getByLabelText('E-mail question key'), 'work_mail');
        await userEvent.click(f.getByRole('button', { name: 'Save mapping' }));

        expect(router.put).toHaveBeenCalledWith(
            '/integrations/meta/mappings/F1',
            { is_enabled: true, lead_source_id: 5, pipeline_stage_id: 9, default_owner_id: 2, field_map: { email: 'work_mail' } },
            expect.anything(),
        );
    });

    it('warns when a Page is not subscribed and can retry the subscription', async () => {
        const props = base();
        props.connections = [connection()];
        props.pages = [page({ subscription_status: 'failed', subscription_error: 'Meta refused.' })];
        render(<Index {...props} />);

        await userEvent.click(screen.getByRole('button', { name: 'Retry subscription' }));

        expect(screen.getByText(/will not arrive until/)).toBeInTheDocument();
        expect(router.post).toHaveBeenCalledWith('/integrations/meta/pages/P1/subscribe', {}, expect.anything());
    });

    it('shows counts and sanitized failures, and offers retry only where replayable', async () => {
        const props = base();
        props.events = [event(), event({ id: 'E2', status: 'imported', failure: null, replayable: false, lead_id: 'LEAD1' })];
        render(<Index {...props} />);

        expect(screen.getByTestId('count-imported')).toHaveTextContent('3');
        expect(screen.getByText('Meta rejected the request')).toBeInTheDocument();
        expect(screen.getAllByRole('button', { name: 'Retry' })).toHaveLength(1);
        expect(screen.getByRole('link', { name: 'Open lead' })).toHaveAttribute('href', '/leads/LEAD1');

        await userEvent.click(screen.getByRole('button', { name: 'Retry' }));
        expect(router.post).toHaveBeenCalledWith('/integrations/meta/events/E1/retry', {}, expect.anything());
    });

    it('flagged duplicates can be created anyway or dismissed, never auto-merged', async () => {
        const props = base();
        props.events = [event({ status: 'needs_review', failure: null, replayable: false, reviewable: true })];
        render(<Index {...props} />);

        expect(screen.getByText(/Possible duplicate of an existing lead. Nothing was created./)).toBeInTheDocument();
        await userEvent.click(screen.getByRole('button', { name: 'Create anyway' }));
        await userEvent.click(screen.getByRole('button', { name: 'Dismiss' }));

        expect(router.post).toHaveBeenCalledWith('/integrations/meta/events/E1/create-anyway', {}, expect.anything());
        expect(router.post).toHaveBeenCalledWith('/integrations/meta/events/E1/dismiss', {}, expect.anything());
    });

    it('never renders anything that looks like a token', () => {
        const props = base();
        props.connections = [connection({ pending_pages: [{ id: '1', name: 'P' }] })];
        props.pages = [page()];
        const { container } = render(<Index {...props} />);

        expect(container.innerHTML).not.toMatch(/access_token|EAA[A-Za-z0-9]{10,}|client_secret/i);
    });
});
