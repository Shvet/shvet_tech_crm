import { fireEvent, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import Index from '../pages/Pipeline/Index';
import { resetInertiaMock, router, setPageProps } from './mocks/inertia';

vi.mock('@inertiajs/react', async () => await import('./mocks/inertia'));

const stage = (id: number, name: string, extra: Record<string, unknown> = {}) => ({ id, name, key: name.toLowerCase(), outcome: 'open' as const, color: null, ...extra });
const card = (id: string, name: string, stageId: number, over: Record<string, unknown> = {}) => ({
    id, full_name: name, company_name: 'Acme', priority: 'medium' as const, estimated_value: '1000.00', owner: 'Asha', stage_id: stageId, last_contacted_at: null, overdue_tasks: 0, can_move: true, ...over,
});

const props = () => ({
    columns: [
        { stage: stage(1, 'New'), count: 2, has_more: false, cards: [card('A', 'Alpha Lead', 1, { overdue_tasks: 2 }), card('B', 'Bravo Lead', 1, { can_move: false, owner: null })] },
        { stage: stage(2, 'Qualified'), count: 0, has_more: false, cards: [] },
        { stage: stage(3, 'Negotiation', { inactive: true }), count: 40, has_more: true, cards: [card('C', 'Charlie Lead', 3)] },
    ],
    filters: { owner: null },
    options: { stages: [stage(1, 'New'), stage(2, 'Qualified')], can_filter_owner: true },
});

describe('Pipeline board', () => {
    beforeEach(() => resetInertiaMock());

    it('shows each stage with its count, cards, empty columns and the truncation notice', () => {
        render(<Index {...props()} />);

        expect(screen.getByRole('listitem', { name: 'New, 2 leads' })).toBeInTheDocument();
        const qualified = screen.getByRole('listitem', { name: 'Qualified, 0 leads' });
        expect(within(qualified).getByText('No leads')).toBeInTheDocument();
        const negotiation = screen.getByRole('listitem', { name: 'Negotiation, 40 leads' });
        expect(within(negotiation).getByText('(inactive)')).toBeInTheDocument();
        expect(within(negotiation).getByText(/Showing 1 of 40/)).toBeInTheDocument();
        expect(within(negotiation).getByRole('link', { name: 'View all' })).toHaveAttribute('href', '/leads?stage=3');
    });

    it('shows lead details on cards: link, overdue badge, unassigned owner', () => {
        render(<Index {...props()} />);

        expect(screen.getByRole('link', { name: 'Alpha Lead' })).toHaveAttribute('href', '/leads/A');
        expect(screen.getByText('2 overdue')).toBeInTheDocument();
        const bravo = screen.getByRole('link', { name: 'Bravo Lead' }).closest('li')!;
        expect(within(bravo).getByText('Unassigned')).toBeInTheDocument();
    });

    it('moving a card with the keyboard-accessible select sends the stage and the stage the client last saw', async () => {
        render(<Index {...props()} />);

        await userEvent.selectOptions(screen.getByLabelText('Move Alpha Lead to stage'), '2');

        expect(router.patch).toHaveBeenCalledWith('/leads/A/stage', { pipeline_stage_id: 2, expected_stage_id: 1 }, { preserveScroll: true });
    });

    it('dragging a card onto another column performs the same move', () => {
        render(<Index {...props()} />);
        const cardEl = screen.getByRole('link', { name: 'Alpha Lead' }).closest('li')!;
        const target = screen.getByRole('listitem', { name: 'Qualified, 0 leads' });

        fireEvent.dragStart(cardEl);
        fireEvent.dragOver(target);
        fireEvent.drop(target);

        expect(router.patch).toHaveBeenCalledWith('/leads/A/stage', { pipeline_stage_id: 2, expected_stage_id: 1 }, { preserveScroll: true });
    });

    it('dropping a card on its own column does nothing', () => {
        render(<Index {...props()} />);
        const cardEl = screen.getByRole('link', { name: 'Alpha Lead' }).closest('li')!;

        fireEvent.dragStart(cardEl);
        fireEvent.drop(screen.getByRole('listitem', { name: 'New, 2 leads' }));

        expect(router.patch).not.toHaveBeenCalled();
    });

    it('cards the user may not move are neither draggable nor offered a move control', () => {
        render(<Index {...props()} />);
        const locked = screen.getByRole('link', { name: 'Bravo Lead' }).closest('li')!;

        expect(locked).toHaveAttribute('draggable', 'false');
        expect(screen.queryByLabelText('Move Bravo Lead to stage')).toBeNull();

        fireEvent.dragStart(locked);
        fireEvent.drop(screen.getByRole('listitem', { name: 'Qualified, 0 leads' }));
        expect(router.patch).not.toHaveBeenCalled();
    });

    it('lets a lead leave a deactivated stage but only offers active stages as targets', () => {
        render(<Index {...props()} />);

        const select = screen.getByLabelText('Move Charlie Lead to stage') as HTMLSelectElement;
        expect(select.value).toBe('3');
        expect(Array.from(select.options).map((o) => o.text)).toEqual(['New', 'Qualified', 'Negotiation']);
    });

    it('shows the conflict message when someone else moved the lead', () => {
        setPageProps({ errors: { stage: 'This lead was moved by someone else. The board has been refreshed.' } });
        render(<Index {...props()} />);

        expect(screen.getByRole('alert')).toHaveTextContent('moved by someone else');
    });

    it('managers can filter the board by owner; agents are not offered the filter', async () => {
        const { unmount } = render(<Index {...props()} />);
        await userEvent.selectOptions(screen.getByLabelText('Show'), 'unassigned');
        expect(router.get).toHaveBeenCalledWith('/pipeline', { owner: 'unassigned' }, { preserveState: true, replace: true });
        unmount();

        const agent = props();
        agent.options.can_filter_owner = false;
        render(<Index {...agent} />);
        expect(screen.queryByLabelText('Show')).toBeNull();
    });
});
