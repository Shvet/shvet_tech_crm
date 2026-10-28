import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import LeadForm from '../components/LeadForm';
import { formControl, resetInertiaMock, setPageProps, submissions } from './mocks/inertia';

vi.mock('@inertiajs/react', async () => await import('./mocks/inertia'));

const options = (canAssign = true) => ({
    stages: [{ id: 1, name: 'New', key: 'new', outcome: 'open' as const, color: null }],
    sources: [{ id: 1, name: 'Website' }, { id: 2, name: 'Manual entry' }],
    owners: canAssign ? [{ id: 7, name: 'Asha' }] : [],
    priorities: ['low', 'medium', 'high'],
    can_assign: canAssign,
});

describe('Lead form', () => {
    beforeEach(() => resetInertiaMock());

    it('posts the entered values with the submission token and no duplicate confirmation', async () => {
        render(<LeadForm mode="create" options={options()} submissionToken="tok-123" />);

        await userEvent.type(screen.getByLabelText('Full name'), 'Asha Verma');
        await userEvent.type(screen.getByLabelText('Phone'), '98765 43210');
        await userEvent.selectOptions(screen.getByLabelText('Priority'), 'high');
        await userEvent.click(screen.getByRole('button', { name: 'Create lead' }));

        expect(submissions).toHaveLength(1);
        expect(submissions[0]).toMatchObject({ method: 'post', url: '/leads', data: { full_name: 'Asha Verma', phone: '98765 43210', priority: 'high', submission_token: 'tok-123', confirm_duplicate: false, lead_source_id: '2' } });
    });

    it('cannot be submitted again while a submission is in flight', async () => {
        formControl.processing = true;
        render(<LeadForm mode="create" options={options()} submissionToken="tok" />);

        const button = screen.getByRole('button', { name: 'Saving…' });
        expect(button).toBeDisabled();
        expect(button).toHaveAttribute('aria-busy', 'true');
        await userEvent.click(button);
        expect(submissions).toHaveLength(0);
    });

    it('associates validation errors with their fields for assistive technology', () => {
        formControl.errors = { full_name: 'The full name field is required.', phone: 'Provide a phone number or an e-mail address.' };
        render(<LeadForm mode="create" options={options()} submissionToken="t" />);

        const name = screen.getByLabelText('Full name');
        expect(name).toHaveAttribute('aria-invalid', 'true');
        expect(name).toHaveAccessibleDescription('The full name field is required.');
        expect(screen.getByLabelText('Phone')).toHaveAccessibleDescription('Provide a phone number or an e-mail address.');
    });

    it('warns about duplicates, links visible ones, hides inaccessible ones, and only resubmits after explicit confirmation', async () => {
        setPageProps({
            errors: { duplicate: 'A lead with the same phone number or e-mail already exists. Review it, or confirm this is a different person.' },
            flash: { status: null, duplicates: [{ visible: true, id: 'L7', full_name: 'Asha Verma', matches: ['phone'], archived: false }, { visible: false, matches: ['email'] }] },
        });
        render(<LeadForm mode="create" options={options()} submissionToken="t" />);

        expect(screen.getByRole('alert')).toHaveTextContent('already exists');
        expect(screen.getByRole('link', { name: 'Asha Verma' })).toHaveAttribute('href', '/leads/L7');
        expect(screen.getByText('A lead you do not have access to')).toBeInTheDocument();
        expect(submissions).toHaveLength(0);

        await userEvent.click(screen.getByRole('button', { name: 'Create anyway' }));

        expect(submissions).toHaveLength(1);
        expect(submissions[0].data).toMatchObject({ confirm_duplicate: true });
    });

    it('only offers an owner picker to users who may assign leads', () => {
        const { unmount } = render(<LeadForm mode="create" options={options(true)} submissionToken="t" />);
        expect(screen.getByLabelText('Owner')).toBeInTheDocument();
        unmount();

        render(<LeadForm mode="create" options={options(false)} submissionToken="t" />);
        expect(screen.queryByLabelText('Owner')).toBeNull();
    });

    it('edit mode patches the lead, prefilled, without create-only fields', async () => {
        const lead = {
            id: 'L1', full_name: 'Old Name', phone: '+919876543210', email: 'old@example.test', company_name: 'Acme', priority: 'low' as const, estimated_value: '10.00',
            source: { id: 1, name: 'Website', key: 'website' }, stage: null, owner: null, created_at: '', updated_at: '', stage_changed_at: null, last_contacted_at: null, archived_at: null,
        };
        render(<LeadForm mode="edit" lead={lead} options={options()} />);

        expect(screen.getByLabelText('Full name')).toHaveValue('Old Name');
        expect(screen.queryByLabelText('First note (optional)')).toBeNull();
        expect(screen.queryByLabelText('Starting stage')).toBeNull();
        await userEvent.clear(screen.getByLabelText('Full name'));
        await userEvent.type(screen.getByLabelText('Full name'), 'New Name');
        await userEvent.click(screen.getByRole('button', { name: 'Save changes' }));

        expect(submissions[0]).toMatchObject({ method: 'patch', url: '/leads/L1', data: { full_name: 'New Name', lead_source_id: '1' } });
    });
});
