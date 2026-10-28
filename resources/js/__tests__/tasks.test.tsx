import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import TaskRow from '../components/TaskRow';
import Index from '../pages/Tasks/Index';
import { resetInertiaMock, router } from './mocks/inertia';

vi.mock('@inertiajs/react', async () => await import('./mocks/inertia'));

const task = (over: Record<string, unknown> = {}) => ({
    id: 'T1', title: 'Send quotation', description: null, priority: 'high' as const, status: 'open' as const, due_at: '2026-10-30T09:00:00Z', is_overdue: false,
    completed_at: null, outcome: null, assignee: { id: 3, name: 'Asha' }, lead: { id: 'L1', name: 'Priya Nair' }, can: { update: true, complete: true }, ...over,
});

describe('TaskRow', () => {
    beforeEach(() => resetInertiaMock());

    it('shows the due time in the workspace timezone, the lead link, priority and assignee', () => {
        render(<ul><TaskRow task={task()} /></ul>);

        expect(screen.getByText(/Due 30 Oct 2026/)).toHaveTextContent('2:30');
        expect(screen.getByRole('link', { name: 'Priya Nair' })).toHaveAttribute('href', '/leads/L1');
        expect(screen.getByText('High')).toBeInTheDocument();
        expect(screen.getByText(/Asha/)).toBeInTheDocument();
    });

    it('flags overdue follow-ups visibly (not by colour alone)', () => {
        render(<ul><TaskRow task={task({ is_overdue: true })} /></ul>);

        expect(screen.getByText('Overdue')).toBeInTheDocument();
    });

    it('completing asks for an optional outcome first and posts it once', async () => {
        render(<ul><TaskRow task={task()} /></ul>);

        await userEvent.click(screen.getByRole('button', { name: 'Complete' }));
        await userEvent.type(screen.getByLabelText('Outcome (optional)'), 'Quote accepted');
        await userEvent.click(screen.getByRole('button', { name: 'Mark complete' }));

        expect(router.post).toHaveBeenCalledTimes(1);
        expect(router.post).toHaveBeenCalledWith('/tasks/T1/complete', { outcome: 'Quote accepted' }, expect.objectContaining({ preserveScroll: true }));
    });

    it('can be cancelled without contacting the server, and a blank outcome is sent as null', async () => {
        render(<ul><TaskRow task={task()} /></ul>);

        await userEvent.click(screen.getByRole('button', { name: 'Complete' }));
        await userEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        expect(router.post).not.toHaveBeenCalled();

        await userEvent.click(screen.getByRole('button', { name: 'Complete' }));
        await userEvent.click(screen.getByRole('button', { name: 'Mark complete' }));
        expect(router.post).toHaveBeenCalledWith('/tasks/T1/complete', { outcome: null }, expect.anything());
    });

    it('completed tasks show their outcome and can be reopened', async () => {
        render(<ul><TaskRow task={task({ status: 'completed', completed_at: '2026-10-29T05:00:00Z', outcome: 'Done deal' })} /></ul>);

        expect(screen.getByText('Outcome: Done deal')).toBeInTheDocument();
        await userEvent.click(screen.getByRole('button', { name: 'Reopen' }));
        expect(router.post).toHaveBeenCalledWith('/tasks/T1/reopen', {}, expect.anything());
    });

    it('offers no actions to users who may not change the task', () => {
        render(<ul><TaskRow task={task({ can: { update: false, complete: false } })} /></ul>);

        expect(screen.queryByRole('button')).toBeNull();
    });
});

describe('Follow-ups page', () => {
    beforeEach(() => resetInertiaMock());

    const props = (over: Record<string, unknown> = {}) => ({
        tasks: { data: [task()], links: [], current_page: 1, last_page: 1, total: 1, from: 1, to: 1 },
        tab: 'overdue' as const,
        assignee: 'me',
        counts: { overdue: 3, today: 1, upcoming: 8 },
        options: { members: [{ id: 3, name: 'Asha' }] },
        ...over,
    });

    it('shows tab counts, marks the active tab and links keep the assignee filter', () => {
        render(<Index {...props({ assignee: 'all' })} />);

        const nav = screen.getByRole('navigation', { name: 'Follow-up views' });
        expect(screen.getByRole('link', { name: /Overdue/ })).toHaveAttribute('aria-current', 'page');
        expect(nav).toHaveTextContent('3');
        expect(screen.getByRole('link', { name: /Upcoming/ })).toHaveAttribute('href', '/tasks?tab=upcoming&assignee=all');
        expect(screen.getByRole('link', { name: /Due today/ })).toHaveAttribute('href', '/tasks?tab=today&assignee=all');
    });

    it('each empty tab has its own message', () => {
        const empty = { data: [], links: [], current_page: 1, last_page: 1, total: 0, from: null, to: null };
        const { rerender } = render(<Index {...props({ tasks: empty })} />);
        expect(screen.getByText('Nothing overdue. Nice work.')).toBeInTheDocument();
        rerender(<Index {...props({ tasks: empty, tab: 'today' })} />);
        expect(screen.getByText('No follow-ups left for today.')).toBeInTheDocument();
        rerender(<Index {...props({ tasks: empty, tab: 'completed' })} />);
        expect(screen.getByText('No completed follow-ups yet.')).toBeInTheDocument();
    });

    it('changing the assignee reloads with the tab preserved; "me" keeps the URL clean', async () => {
        render(<Index {...props({ tab: 'upcoming' })} />);

        await userEvent.selectOptions(screen.getByLabelText('Assigned to'), 'all');
        expect(router.get).toHaveBeenLastCalledWith('/tasks', { tab: 'upcoming', assignee: 'all' }, { preserveState: true, replace: true });

        await userEvent.selectOptions(screen.getByLabelText('Assigned to'), 'me');
        expect(router.get).toHaveBeenLastCalledWith('/tasks', { tab: 'upcoming' }, expect.anything());
    });
});
