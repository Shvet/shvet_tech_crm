import { fireEvent, render, screen, within } from '@testing-library/react';
import type { ComponentProps } from 'react';
import userEvent from '@testing-library/user-event';
import Index from '../pages/Leads/Index';
import { resetInertiaMock, router, setPageProps } from './mocks/inertia';

vi.mock('@inertiajs/react', async () => await import('./mocks/inertia'));

const stage = (id: number, name: string, outcome: 'open' | 'won' | 'lost' = 'open') => ({ id, name, key: name.toLowerCase(), outcome, color: null });
type LeadRow = ComponentProps<typeof Index>['leads']['data'][number];
const lead = (id: string, name: string, over: Partial<LeadRow> = {}): LeadRow => ({
    id, full_name: name, phone: '+919876543210', email: `${id}@example.test`, company_name: 'Acme Co', priority: 'high' as const, estimated_value: '5000.00',
    source: { id: 1, name: 'Manual entry', key: 'manual' }, stage: stage(1, 'New'), owner: { id: 7, name: 'Asha' },
    created_at: '2026-10-01T05:00:00Z', updated_at: '2026-10-01T05:00:00Z', stage_changed_at: null, last_contacted_at: null, archived_at: null, ...over,
});

const baseProps = (): ComponentProps<typeof Index> => ({
    leads: { data: [lead('L1', 'Priya Nair'), lead('L2', 'Ravi Shah', { owner: null })], links: [], current_page: 1, last_page: 1, total: 2, from: 1, to: 2 },
    filters: { q: '', stage: null, source: null, owner: null, priority: null, archived: false, sort: 'created', dir: 'desc' },
    options: { stages: [stage(1, 'New'), stage(2, 'Won', 'won')], sources: [{ id: 1, name: 'Manual entry' }], owners: [{ id: 7, name: 'Asha' }], priorities: ['low', 'medium', 'high'] },
    can: { create: true, see_archived: true },
});

describe('Leads list', () => {
    beforeEach(() => resetInertiaMock());

    it('lists leads with links, owner fallback and a New lead action', () => {
        render(<Index {...baseProps()} />);

        expect(screen.getByRole('link', { name: 'Priya Nair' })).toHaveAttribute('href', '/leads/L1');
        expect(screen.getByText('Unassigned', { selector: 'td span' })).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'New lead' })).toHaveAttribute('href', '/leads/create');
        expect(screen.getAllByText('₹5,000.00', { exact: false }).length).toBeGreaterThan(0);
    });

    it('submitting the search sends only the non-empty filters', async () => {
        render(<Index {...baseProps()} />);

        await userEvent.type(screen.getByLabelText('Search'), 'priya{Enter}');

        expect(router.get).toHaveBeenCalledWith('/leads', { q: 'priya', sort: 'created', dir: 'desc' }, { preserveState: true, replace: true });
    });

    it('changing a filter applies immediately and keeps the typed search text', async () => {
        render(<Index {...baseProps()} />);
        await userEvent.type(screen.getByLabelText('Search'), 'nair');

        await userEvent.selectOptions(screen.getByLabelText('Stage'), '2');

        expect(router.get).toHaveBeenLastCalledWith('/leads', expect.objectContaining({ q: 'nair', stage: '2' }), expect.anything());
    });

    it('clicking a sortable column toggles direction and exposes aria-sort', async () => {
        const props = baseProps();
        props.filters = { ...props.filters, sort: 'name', dir: 'asc' };
        render(<Index {...props} />);

        const header = screen.getByRole('columnheader', { name: /Name/ });
        expect(header).toHaveAttribute('aria-sort', 'ascending');
        await userEvent.click(within(header).getByRole('button'));

        expect(router.get).toHaveBeenCalledWith('/leads', expect.objectContaining({ sort: 'name', dir: 'desc' }), expect.anything());
    });

    it('shows a filtered empty state with a way to clear filters', async () => {
        const props = baseProps();
        props.leads = { ...props.leads, data: [], total: 0, from: null, to: null };
        props.filters = { ...props.filters, q: 'zzz' };
        render(<Index {...props} />);

        expect(screen.getByText('No leads match these filters.')).toBeInTheDocument();
        await userEvent.click(screen.getByRole('button', { name: 'Clear filters' }));
        expect(router.get).toHaveBeenCalledWith('/leads', {}, { replace: true });
    });

    it('shows the first-run empty state when there are no leads at all', () => {
        const props = baseProps();
        props.leads = { ...props.leads, data: [], total: 0, from: null, to: null };
        render(<Index {...props} />);

        expect(screen.getByText(/No leads yet/)).toBeInTheDocument();
    });

    it('hides the owner filter, create and archive controls for users without those abilities', () => {
        const props = baseProps();
        props.options.owners = [];
        props.can = { create: false, see_archived: false };
        render(<Index {...props} />);

        expect(screen.queryByLabelText('Owner')).toBeNull();
        expect(screen.queryByRole('link', { name: 'New lead' })).toBeNull();
        expect(screen.queryByRole('button', { name: /archived/i })).toBeNull();
    });

    it('toggles the archived view for managers', async () => {
        render(<Index {...baseProps()} />);

        await userEvent.click(screen.getByRole('button', { name: 'Show archived' }));

        expect(router.get).toHaveBeenCalledWith('/leads', expect.objectContaining({ archived: '1' }), expect.anything());
    });

    it('renders hostile text as text', () => {
        const props = baseProps();
        props.leads.data = [lead('L9', '<img src=x onerror=alert(1)>')];
        const { container } = render(<Index {...props} />);

        expect(container.querySelector('img')).toBeNull();
        expect(screen.getByText('<img src=x onerror=alert(1)>')).toBeInTheDocument();
        setPageProps();
        fireEvent.click(screen.getByText('<img src=x onerror=alert(1)>'));
    });
});
