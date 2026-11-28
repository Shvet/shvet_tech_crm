import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import Import from '../pages/Leads/Import';
import Reports from '../pages/Reports/Index';
import Pipeline from '../pages/Settings/Pipeline';
import { resetInertiaMock, router, submissions } from './mocks/inertia';

vi.mock('@inertiajs/react', async () => await import('./mocks/inertia'));

describe('Import leads page', () => {
    beforeEach(() => resetInertiaMock());

    const props = { sources: [{ id: 3, name: 'Website' }, { id: 5, name: 'Manual entry' }], result: null, limits: { rows: 2000, kb: 2048 } };

    it('needs a file, defaults to the manual source and sends a dry run from "Check file"', async () => {
        render(<Import {...props} />);
        expect(screen.getByRole('button', { name: 'Import' })).toBeDisabled();
        expect(screen.getByLabelText(/Default source/)).toHaveValue('5');

        const file = new File(['name,phone\nA a,9876543210\n'], 'leads.csv', { type: 'text/csv' });
        await userEvent.upload(screen.getByLabelText(/CSV file/), file);
        await userEvent.click(screen.getByRole('button', { name: 'Check file' }));

        expect(submissions.at(-1)).toMatchObject({ method: 'post', url: '/leads/import', data: { lead_source_id: '5', dry_run: true, allow_duplicates: false } });
        expect(submissions.at(-1)?.data.file).toBe(file);

        await userEvent.click(screen.getByLabelText(/Import possible duplicates/));
        await userEvent.click(screen.getByRole('button', { name: 'Import' }));
        expect(submissions.at(-1)?.data).toMatchObject({ dry_run: false, allow_duplicates: true });
    });

    it('shows the result with row errors', () => {
        render(<Import {...props} result={{ total: 3, created: 1, duplicates: 1, failed: 1, dry_run: false, errors: [{ line: 4, messages: ['The name field is required.'] }] }} />);
        const result = screen.getByRole('status', { name: 'Import result' });
        expect(within(result).getByText('Import finished.')).toBeInTheDocument();
        expect(within(result).getByText('Created: 1')).toBeInTheDocument();
        expect(within(result).getByText(/Line 4:/).parentElement).toHaveTextContent('The name field is required.');
    });
});

describe('Pipeline stage settings', () => {
    beforeEach(() => resetInertiaMock());

    const stages = [
        { id: 1, name: 'New', key: 'new', outcome: 'open' as const, is_active: true, leads: 2 },
        { id: 2, name: 'Contacted', key: 'contacted', outcome: 'open' as const, is_active: true, leads: 0 },
        { id: 3, name: 'Won', key: 'won', outcome: 'won' as const, is_active: true, leads: 1 },
    ];

    it('reorders, renames, deactivates and adds stages', async () => {
        render(<Pipeline stages={stages} />);

        await userEvent.click(screen.getByRole('button', { name: 'Move Contacted up' }));
        expect(router.put).toHaveBeenCalledWith('/settings/pipeline/order', { ids: [2, 1, 3] }, expect.any(Object));
        expect(screen.getByRole('button', { name: 'Move New up' })).toBeDisabled();

        const name = screen.getByLabelText('Name of Won');
        await userEvent.clear(name);
        await userEvent.type(name, 'Closed won');
        await userEvent.click(screen.getByRole('button', { name: 'Rename' }));
        expect(router.patch).toHaveBeenCalledWith('/settings/pipeline/stages/3', { name: 'Closed won' }, expect.any(Object));

        await userEvent.click(within(screen.getByRole('listitem', { name: 'Contacted' })).getByRole('button', { name: 'Deactivate' }));
        expect(router.patch).toHaveBeenCalledWith('/settings/pipeline/stages/2', { is_active: false }, expect.any(Object));

        await userEvent.type(screen.getByLabelText('Name'), 'Site visit');
        await userEvent.click(screen.getByRole('button', { name: 'Add stage' }));
        expect(submissions.at(-1)).toMatchObject({ method: 'post', url: '/settings/pipeline/stages', data: { name: 'Site visit', outcome: 'open' } });
    });
});

describe('Reports', () => {
    beforeEach(() => resetInertiaMock());

    const report = {
        range: { from: '2026-10-30', to: '2026-11-28' },
        summary: { created: 3, won: 1, lost: 1, won_value: '1000.00', win_rate: 50, avg_days_to_win: 10 },
        by_source: [{ key: '1', name: 'Website', created: 2, won: 1, lost: 1, won_value: '1000.00', open: 0, open_value: '0', win_rate: 50 }],
        by_owner: [{ key: 'none', name: 'Unassigned', created: 3, won: 1, lost: 1, won_value: '1000.00', open: 1, open_value: '500', win_rate: 50 }],
        funnel: [{ name: 'Qualified', count: 1, value: '500.00' }],
    };

    it('shows the summary and breakdowns and switches periods', async () => {
        render(<Reports report={report} preset="30" />);

        expect(screen.getByText('Win rate', { selector: 'dt' }).nextSibling).toHaveTextContent('50%');
        expect(screen.getByRole('rowheader', { name: 'Website' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Last 30 days' })).toHaveAttribute('aria-pressed', 'true');

        await userEvent.click(screen.getByRole('button', { name: 'Last 90 days' }));
        expect(router.get).toHaveBeenCalledWith('/reports', { range: '90' }, expect.any(Object));

        await userEvent.clear(screen.getByLabelText('From'));
        await userEvent.type(screen.getByLabelText('From'), '2026-01-01');
        await userEvent.click(screen.getByRole('button', { name: 'Show' }));
        expect(router.get).toHaveBeenCalledWith('/reports', { from: '2026-01-01', to: '2026-11-28' }, expect.any(Object));
    });

    it('says so when the period is empty', () => {
        render(<Reports report={{ ...report, summary: { created: 0, won: 0, lost: 0, won_value: '0', win_rate: null, avg_days_to_win: null }, by_source: [], by_owner: [], funnel: [] }} preset="7" />);
        expect(screen.getByText('No lead activity in this period yet.')).toBeInTheDocument();
    });
});
