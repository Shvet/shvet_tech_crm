import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import Show from '../pages/Leads/Show';
import { resetInertiaMock, router, submissions } from './mocks/inertia';

vi.mock('@inertiajs/react', async () => await import('./mocks/inertia'));

const stage = (id: number, name: string) => ({ id, name, key: name.toLowerCase(), outcome: 'open' as const, color: null });
const can = (over: Record<string, boolean> = {}) => ({ update: true, assign: true, change_stage: true, log_activity: true, create_task: true, archive: true, restore: false, ...over });

const props = (over: { can?: Record<string, boolean>; lead?: Record<string, unknown> } = {}) => ({
    lead: {
        id: 'L1', full_name: 'Priya Nair', phone: '+919811122233', email: 'priya@example.test', company_name: 'Nair Textiles', priority: 'high' as const, estimated_value: '50000.00',
        source: { id: 1, name: 'Website', key: 'website' }, stage: stage(1, 'New'), owner: { id: 7, name: 'Asha' }, created_at: '2026-10-01T05:00:00Z', updated_at: '2026-10-01T05:00:00Z',
        stage_changed_at: null, last_contacted_at: null, archived_at: null, can: can(over.can), ...over.lead,
    },
    timeline: [
        { kind: 'activity' as const, id: 'a1', at: '2026-10-28T05:00:00Z', type: 'note' as const, subject: null, body: '<b>bold</b> wants a demo', user: 'Asha' },
        { kind: 'stage' as const, id: 's1', at: '2026-10-27T05:00:00Z', from: null, to: 'New', user: 'Asha' },
    ],
    tasks: [],
    options: { stages: [stage(1, 'New'), stage(2, 'Qualified')], owners: [{ id: 7, name: 'Asha' }, { id: 8, name: 'Ravi' }], assignees: [{ id: 7, name: 'Asha' }, { id: 8, name: 'Ravi' }], activity_types: ['call', 'meeting', 'note', 'email', 'other'], priorities: ['low', 'medium', 'high'] },
    viewer_id: 7,
});

describe('Lead detail page', () => {
    beforeEach(() => {
        resetInertiaMock();
        vi.restoreAllMocks();
    });

    it('changing the stage sends the stage and the stage the page was rendered with', async () => {
        render(<Show {...props()} />);

        await userEvent.selectOptions(screen.getByLabelText('Stage'), '2');

        expect(router.patch).toHaveBeenCalledWith('/leads/L1/stage', { pipeline_stage_id: '2', expected_stage_id: 1 }, { preserveScroll: true });
    });

    it('assigners can change or clear the owner', async () => {
        render(<Show {...props()} />);

        await userEvent.selectOptions(screen.getByLabelText('Owner'), '8');
        expect(router.patch).toHaveBeenLastCalledWith('/leads/L1/owner', { owner_id: '8' }, { preserveScroll: true });
        await userEvent.selectOptions(screen.getByLabelText('Owner'), '');
        expect(router.patch).toHaveBeenLastCalledWith('/leads/L1/owner', { owner_id: null }, { preserveScroll: true });
    });

    it('an agent sees the owner as text and has no archive button', () => {
        render(<Show {...props({ can: { assign: false, archive: false } })} />);

        expect(screen.queryByLabelText('Owner')).toBeNull();
        expect(screen.getByText('Asha', { selector: 'dd' })).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Archive' })).toBeNull();
    });

    it('archiving requires confirmation', async () => {
        const confirm = vi.spyOn(window, 'confirm').mockReturnValueOnce(false).mockReturnValueOnce(true);
        render(<Show {...props()} />);

        await userEvent.click(screen.getByRole('button', { name: 'Archive' }));
        expect(router.post).not.toHaveBeenCalled();
        await userEvent.click(screen.getByRole('button', { name: 'Archive' }));
        expect(router.post).toHaveBeenCalledWith('/leads/L1/archive');
        expect(confirm).toHaveBeenCalledTimes(2);
    });

    it('an archived lead shows a read-only notice and offers restore instead of edit/archive', async () => {
        render(<Show {...props({ lead: { archived_at: '2026-10-20T05:00:00Z' }, can: { update: false, archive: false, restore: true, change_stage: false, log_activity: false, create_task: false } })} />);

        expect(screen.getByRole('status')).toHaveTextContent('archived');
        expect(screen.queryByRole('link', { name: 'Edit' })).toBeNull();
        expect(screen.getByLabelText('Stage')).toBeDisabled();
        await userEvent.click(screen.getByRole('button', { name: 'Restore' }));
        expect(router.post).toHaveBeenCalledWith('/leads/L1/restore');
        expect(screen.queryByRole('button', { name: 'Record activity' })).toBeNull();
    });

    it('shows the timeline newest first with stage moves and escapes note markup', () => {
        const { container } = render(<Show {...props()} />);
        const timeline = screen.getByRole('heading', { name: 'Timeline' }).closest('section')!;

        expect(within(timeline).getByText('Started in New')).toBeInTheDocument();
        expect(within(timeline).getByText('<b>bold</b> wants a demo')).toBeInTheDocument();
        expect(container.querySelector('b')).toBeNull();
    });

    it('logging an activity posts the form and clears the text fields', async () => {
        render(<Show {...props()} />);

        await userEvent.selectOptions(screen.getByLabelText('Type'), 'meeting');
        await userEvent.type(screen.getByLabelText('Subject'), 'Site visit');
        await userEvent.click(screen.getByRole('button', { name: 'Record activity' }));

        expect(submissions[0]).toMatchObject({ method: 'post', url: '/leads/L1/activities', data: { type: 'meeting', subject: 'Site visit' } });
        expect(screen.getByLabelText('Subject')).toHaveValue('');
    });

    it('scheduling a follow-up defaults to the viewer, a future due time, and lists only permitted assignees', async () => {
        render(<Show {...props()} />);

        await userEvent.click(screen.getByText('Schedule a follow-up'));
        await userEvent.type(screen.getByLabelText('Follow-up'), 'Send quotation');
        await userEvent.click(screen.getByRole('button', { name: 'Schedule follow-up' }));

        const sent = submissions.find((s) => s.url === '/leads/L1/tasks')!;
        expect(sent.data).toMatchObject({ title: 'Send quotation', assigned_to: '7', priority: 'medium' });
        expect(String(sent.data.due_at)).toMatch(/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/);
        expect(screen.getByLabelText('Assign to')).toBeInTheDocument();
    });

    it('does not show the assignee picker when only the viewer can be assigned', () => {
        const p = props();
        p.options.assignees = [{ id: 7, name: 'Asha' }];
        render(<Show {...p} />);

        expect(screen.queryByLabelText('Assign to')).toBeNull();
    });
});
