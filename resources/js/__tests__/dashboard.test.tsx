import { render, screen, within } from '@testing-library/react';
import Dashboard from '../pages/Dashboard';
import { resetInertiaMock } from './mocks/inertia';

vi.mock('@inertiajs/react', async () => await import('./mocks/inertia'));

const metrics = (over: Record<string, unknown> = {}) => ({
    totals: { leads: 7, new_this_week: 6, open: 5, won: 1, lost: 1, open_value: '7500', unassigned: 1 },
    stages: [
        { id: 1, name: 'New', key: 'new', outcome: 'open', color: null, count: 3, value: '3500' },
        { id: 2, name: 'Won', key: 'won', outcome: 'won', color: null, count: 1, value: '8000' },
    ],
    recent_activity: [{ id: 1, type: 'call', subject: 'Intro call', occurred_at: '2026-10-28T04:30:00Z', user: 'Asha', lead: { id: 'L1', name: 'Priya Nair' } }],
    tasks: { overdue: 2, today: 1, upcoming: 4 },
    ...over,
});
const lists = { overdue: [], today: [], upcoming: [] };

describe('Dashboard', () => {
    beforeEach(() => resetInertiaMock());

    it('renders the headline numbers and links the task counters to the right follow-up tab', () => {
        render(<Dashboard metrics={metrics() as never} task_lists={lists} />);

        expect(screen.getByText('Total leads').nextSibling).toHaveTextContent('7');
        expect(screen.getByText('Won / Lost').nextSibling).toHaveTextContent('1 / 1');
        expect(screen.getByText('Open pipeline').nextSibling).toHaveTextContent('₹7,500');
        expect(screen.getByText('Overdue follow-ups', { selector: 'p' }).closest('a')).toHaveAttribute('href', '/tasks?tab=overdue&assignee=all');
        expect(screen.getByText('Unassigned leads').nextSibling).toHaveTextContent('1');
    });

    it('does not show an unassigned counter to users who may not see it', () => {
        const m = metrics();
        (m.totals as { unassigned: number | null }).unassigned = null;
        render(<Dashboard metrics={m as never} task_lists={lists} />);

        expect(screen.queryByText('Unassigned leads')).toBeNull();
    });

    it('lists stages with counts and recent activity with the actor and lead link', () => {
        render(<Dashboard metrics={metrics() as never} task_lists={lists} />);

        const stages = screen.getByRole('heading', { name: 'Leads by stage' }).closest('section')!;
        expect(within(stages).getByRole('link', { name: 'New' })).toHaveAttribute('href', '/leads?stage=1');
        expect(stages).toHaveTextContent('3 · ₹3,500');
        const recent = screen.getByRole('heading', { name: 'Recent activity' }).closest('section')!;
        expect(within(recent).getByRole('link', { name: 'Priya Nair' })).toHaveAttribute('href', '/leads/L1');
        expect(recent).toHaveTextContent('Intro call');
        expect(recent).toHaveTextContent('Asha');
    });

    it('has friendly empty states for a brand-new workspace', () => {
        const m = metrics({ totals: { leads: 0, new_this_week: 0, open: 0, won: 0, lost: 0, open_value: '0', unassigned: 0 }, recent_activity: [], tasks: { overdue: 0, today: 0, upcoming: 0 } });
        render(<Dashboard metrics={m as never} task_lists={lists} />);

        expect(screen.getByText('No leads yet.')).toBeInTheDocument();
        expect(screen.getByText('No activity recorded yet.')).toBeInTheDocument();
        expect(screen.getAllByText('None.')).toHaveLength(3);
    });

    it('renders follow-up lists with their lead and overdue flag', () => {
        const task = { id: 'T1', title: 'Call back', description: null, priority: 'high', status: 'open', due_at: '2026-10-27T09:00:00Z', is_overdue: true, completed_at: null, outcome: null, assignee: { id: 1, name: 'Asha' }, lead: { id: 'L1', name: 'Priya Nair' }, can: { update: false, complete: false } };
        render(<Dashboard metrics={metrics() as never} task_lists={{ ...lists, overdue: [task as never] }} />);

        const card = screen.getByRole('heading', { name: 'Overdue follow-ups' }).closest('section')!;
        expect(within(card).getByText('Call back')).toBeInTheDocument();
        expect(within(card).getByText('Overdue')).toBeInTheDocument();
    });
});
