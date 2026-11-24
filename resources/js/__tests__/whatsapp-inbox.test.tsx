import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import Index from '../pages/Inbox/Index';
import Setup from '../pages/Integrations/WhatsApp/Index';
import { visibleNav } from '../lib/permissions';
import { formControl, resetInertiaMock, router, setPageProps, submissions } from './mocks/inertia';

vi.mock('@inertiajs/react', async () => await import('./mocks/inertia'));

const summary = (over: Record<string, unknown> = {}) => ({
    id: 'C1', name: 'Ravi Kumar', phone: '+919876543210', lead: { id: 'L1', name: 'Ravi Kumar' }, link_status: 'linked', unread: 0, last_message_at: '2026-11-23T10:00:00Z', opted_out: false, ...over,
});
const detail = (over: Record<string, unknown> = {}) => ({
    ...summary(),
    window_open: true,
    window_closes_at: '2026-11-24T10:00:00Z',
    account_active: true,
    messages: [
        { id: 'M1', direction: 'in', kind: 'text', body: 'Is the flat available?', template_name: null, status: 'received', error: null, sender: null, at: '2026-11-23T10:00:00Z' },
        { id: 'M2', direction: 'out', kind: 'text', body: 'Yes!', template_name: null, status: 'failed', error: 'Meta could not deliver to this number.', sender: 'Asha', at: '2026-11-23T10:01:00Z' },
    ],
    templates: [{ id: 7, name: 'follow_up', language: 'en', params: 2, body: 'Hi {{1}}, about {{2}}' }],
    link_candidates: [],
    can: { reply: true, link: false },
    ...over,
});
const props = (over: Record<string, unknown> = {}) => ({
    conversations: [summary(), summary({ id: 'C2', name: 'Meera', lead: null, link_status: 'ambiguous', unread: 2 })],
    filters: { q: '', filter: 'all' as const },
    account: { status: 'active', display_phone_number: '+91 22 4000 0000' },
    conversation: detail(),
    poll_seconds: 10,
    can: { manage: false },
    ...over,
});

beforeEach(() => resetInertiaMock());

describe('WhatsApp inbox', () => {
    it('lists conversations with unread counts and shows the open thread with delivery problems', () => {
        render(<Index {...(props() as any)} />);
        const meera = screen.getByText('Meera').closest('a')!;
        expect(meera).toHaveAttribute('href', '/inbox/C2');
        expect(meera).toHaveTextContent('2 unread');
        expect(screen.getByText('Several leads match')).toBeInTheDocument();
        expect(screen.getByText('Is the flat available?')).toBeInTheDocument();
        expect(screen.getByRole('alert')).toHaveTextContent('Meta could not deliver');
        expect(screen.getByRole('link', { name: 'Open lead: Ravi Kumar' })).toHaveAttribute('href', '/leads/L1');
    });

    it('sends a free-text reply inside the 24-hour window', async () => {
        render(<Index {...(props() as any)} />);
        const send = screen.getByRole('button', { name: 'Send' });
        expect(send).toBeDisabled();
        await userEvent.type(screen.getByLabelText('Message'), 'Calling you now');
        await userEvent.click(send);
        expect(submissions.at(-1)).toEqual({ method: 'post', url: '/inbox/C1/messages', data: { type: 'text', body: 'Calling you now' } });
    });

    it('only offers templates once the window has closed, and asks for each placeholder', async () => {
        render(<Index {...(props({ conversation: detail({ window_open: false }) }) as any)} />);
        expect(screen.queryByLabelText('Message')).not.toBeInTheDocument();
        expect(screen.getByText(/only an approved template/)).toBeInTheDocument();

        await userEvent.selectOptions(screen.getByLabelText('Template'), '7');
        expect(screen.getByText('Hi {{1}}, about {{2}}')).toBeInTheDocument();
        await userEvent.type(screen.getByLabelText('Value for {{1}}'), 'Ravi');
        await userEvent.type(screen.getByLabelText('Value for {{2}}'), 'the 2BHK');
        await userEvent.click(screen.getByRole('button', { name: 'Send' }));
        expect(submissions.at(-1)?.data).toEqual({ type: 'template', template_id: 7, params: ['Ravi', 'the 2BHK'] });
    });

    it('blocks the composer for opted-out contacts, read-only users and a disconnected number', () => {
        const { rerender } = render(<Index {...(props({ conversation: detail({ opted_out: true }) }) as any)} />);
        expect(screen.getByText(/opted out/)).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Send' })).not.toBeInTheDocument();

        rerender(<Index {...(props({ conversation: detail({ can: { reply: false, link: false } }) }) as any)} />);
        expect(screen.getByText(/read this conversation but not reply/)).toBeInTheDocument();

        rerender(<Index {...(props({ conversation: detail({ account_active: false }) }) as any)} />);
        expect(screen.getByText(/must reconnect it/)).toBeInTheDocument();
    });

    it('lets a manager link an unlinked chat to a matching lead', async () => {
        render(<Index {...(props({ conversation: detail({ lead: null, link_status: 'ambiguous', can: { reply: true, link: true }, link_candidates: [{ id: 'L8', name: 'Ravi A' }, { id: 'L9', name: 'Ravi B' }] }) }) as any)} />);
        await userEvent.selectOptions(screen.getByLabelText('Link to lead'), 'L9');
        await userEvent.click(screen.getByRole('button', { name: 'Link' }));
        expect(router.post).toHaveBeenCalledWith('/inbox/C1/link', { lead_id: 'L9' }, expect.anything());
    });

    it('filters and searches through the query string', async () => {
        render(<Index {...(props() as any)} />);
        await userEvent.click(screen.getByRole('button', { name: 'Unread' }));
        expect(router.get).toHaveBeenLastCalledWith('/inbox/C1', { filter: 'unread' }, expect.anything());
        await userEvent.type(screen.getByLabelText('Search conversations'), 'meera');
        await userEvent.click(screen.getByRole('button', { name: 'Search' }));
        expect(router.get).toHaveBeenLastCalledWith('/inbox/C1', { q: 'meera' }, expect.anything());
    });

    it('polls for new messages only while the tab is visible', () => {
        vi.useFakeTimers();
        render(<Index {...(props() as any)} />);
        vi.advanceTimersByTime(10_000);
        expect(router.reload).toHaveBeenCalledWith({ only: ['conversations', 'conversation'] });
        vi.useRealTimers();
    });

    it('tells non-admins to ask an admin when WhatsApp is not connected', () => {
        render(<Index {...(props({ account: null, conversation: null }) as any)} />);
        expect(screen.getByRole('status')).toHaveTextContent('Ask an admin');
    });

    it('shows the inbox in navigation for anyone who can view conversations', () => {
        expect(visibleNav(['conversations.view']).map((i) => i.label)).toEqual(['WhatsApp inbox']);
        expect(visibleNav(['integrations.manage']).map((i) => i.label)).toContain('WhatsApp setup');
    });
});

describe('WhatsApp setup page', () => {
    const admin = { id: 't1', name: 'Acme', timezone: 'Asia/Kolkata', currency: 'INR', role: 'Admin', permissions: ['integrations.manage'] };
    const setup = { configured: true, missing: [], webhook_url: 'https://crm.test/webhooks/whatsapp', app_id: '1' };

    it('connects with the ids and a token, and never pre-fills the token', async () => {
        setPageProps({ tenant: admin });
        render(<Setup setup={setup} account={null} templates={[]} members={[{ id: 4, name: 'Asha' }]} />);
        expect(screen.getByLabelText('System User access token')).toHaveAttribute('type', 'password');
        await userEvent.type(screen.getByLabelText('WhatsApp Business Account id'), '2200');
        await userEvent.type(screen.getByLabelText('Phone number id'), '1100');
        await userEvent.type(screen.getByLabelText('System User access token'), 'EAAG-token-value-123456');
        await userEvent.selectOptions(screen.getByLabelText('Assign new WhatsApp leads to'), '4');
        await userEvent.click(screen.getByRole('button', { name: 'Connect' }));
        expect(submissions.at(-1)).toEqual({
            method: 'post',
            url: '/integrations/whatsapp',
            data: { waba_id: '2200', phone_number_id: '1100', access_token: 'EAAG-token-value-123456', auto_create_leads: true, default_owner_id: 4 },
        });
    });

    it('shows what is missing on the server and disables connecting', () => {
        setPageProps({ tenant: admin });
        render(<Setup setup={{ ...setup, configured: false, missing: ['META_APP_SECRET'] }} account={null} templates={[]} members={[]} />);
        expect(screen.getByRole('alert')).toHaveTextContent('META_APP_SECRET');
        expect(screen.getByRole('button', { name: 'Connect' })).toBeDisabled();
    });

    it('shows a reconnect warning and the sanitized error for a revoked token', () => {
        setPageProps({ tenant: admin });
        const account = {
            id: 'A1', phone_number_id: '1100', waba_id: '2200', display_phone_number: '+91 22 4000 0000', verified_name: 'Acme', status: 'reauthorization_required' as const,
            subscription_status: 'subscribed', auto_create_leads: true, default_owner_id: null, last_validated_at: null, last_webhook_at: null, templates_synced_at: null,
            error: 'Meta rejected the saved access token.',
        };
        render(<Setup setup={setup} account={account} templates={[]} members={[]} />);
        expect(screen.getByText('Reconnect required')).toBeInTheDocument();
        expect(screen.getByRole('alert')).toHaveTextContent('Meta rejected the saved access token.');
        expect(screen.queryByRole('button', { name: 'Refresh from Meta' })).not.toBeInTheDocument();
    });

    it('is hidden from users without integrations.manage', () => {
        render(<Setup setup={setup} account={null} templates={[]} members={[]} />);
        expect(screen.getByRole('alert')).toHaveTextContent('do not have access');
        expect(formControl.processing).toBe(false);
    });
});
