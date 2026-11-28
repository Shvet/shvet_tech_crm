import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import AppLayout from '../../components/AppLayout';
import { Button, Card, Field, Select } from '../../components/ui';

interface ImportResult {
    total: number;
    created: number;
    duplicates: number;
    failed: number;
    dry_run: boolean;
    errors: { line: number; messages: string[] }[];
}

interface Props {
    sources: { id: number; name: string }[];
    result: ImportResult | null;
    limits: { rows: number; kb: number };
}

const COLUMNS: [string, string][] = [
    ['name', 'Required. The lead’s full name.'],
    ['phone', 'Phone or e-mail is required. Numbers without a country code are read as Indian numbers.'],
    ['email', ''],
    ['company', ''],
    ['source', 'Name of a lead source. Empty uses the default below.'],
    ['stage', 'Name of an active pipeline stage. Empty uses the first stage.'],
    ['owner_email', 'E-mail of an active member. Empty leaves the lead unassigned.'],
    ['priority', 'low, medium or high. Empty is medium.'],
    ['value', 'Estimated value, e.g. 25000 or 25000.50.'],
    ['notes', 'Saved as a note on the lead.'],
];

export default function Import({ sources, result, limits }: Props) {
    const form = useForm<{ file: File | null; lead_source_id: string; allow_duplicates: boolean; dry_run: boolean }>({
        file: null,
        lead_source_id: String(sources.find((s) => s.name.toLowerCase().includes('manual'))?.id ?? sources[0]?.id ?? ''),
        allow_duplicates: false,
        dry_run: true,
    });

    const submit = (dryRun: boolean) => (e: FormEvent) => {
        e.preventDefault();
        form.transform((data) => ({ ...data, dry_run: dryRun }));
        form.post('/leads/import', { forceFormData: true, preserveScroll: true });
    };

    return (
        <AppLayout title="Import leads">
            <Head title="Import leads" />
            <p className="mb-4 text-sm">
                <Link href="/leads" className="text-indigo-700 hover:underline">
                    ← Back to leads
                </Link>
            </p>

            {result && (
                <div role="status" aria-label="Import result" className="mb-6 rounded-md border border-slate-200 bg-white p-4 text-sm">
                    <p className="font-medium">
                        {result.dry_run ? 'Check finished. Nothing was imported yet.' : 'Import finished.'}
                    </p>
                    <ul className="mt-2 space-y-0.5">
                        <li>Rows read: {result.total}</li>
                        <li>{result.dry_run ? 'Would be created' : 'Created'}: {result.created}</li>
                        <li>Skipped as possible duplicates: {result.duplicates}</li>
                        <li>Rows with errors: {result.failed}</li>
                    </ul>
                    {result.errors.length > 0 && (
                        <div className="mt-3">
                            <p className="font-medium text-red-700">Fix these rows and import the file again (rows already created are skipped as duplicates):</p>
                            <ul aria-label="Row errors" className="mt-1 max-h-72 space-y-1 overflow-y-auto">
                                {result.errors.map((err) => (
                                    <li key={err.line}>
                                        <span className="font-mono">Line {err.line}:</span> {err.messages.join(' ')}
                                    </li>
                                ))}
                            </ul>
                            {result.failed > result.errors.length && <p className="mt-1 text-slate-500">Only the first {result.errors.length} errors are shown.</p>}
                        </div>
                    )}
                </div>
            )}

            <form onSubmit={submit(false)} className="max-w-xl space-y-4" noValidate>
                <Field id="file" label={`CSV file (up to ${limits.rows.toLocaleString()} rows, ${Math.round(limits.kb / 1024)} MB)`} error={form.errors.file}>
                    <input
                        id="file"
                        type="file"
                        accept=".csv,text/csv"
                        className="block w-full text-sm"
                        aria-invalid={form.errors.file ? true : undefined}
                        onChange={(e) => form.setData('file', e.target.files?.[0] ?? null)}
                    />
                </Field>
                <Field id="lead_source_id" label="Default source (for rows without one)" error={form.errors.lead_source_id}>
                    <Select id="lead_source_id" value={form.data.lead_source_id} onChange={(e) => form.setData('lead_source_id', e.target.value)}>
                        {sources.map((s) => (
                            <option key={s.id} value={s.id}>
                                {s.name}
                            </option>
                        ))}
                    </Select>
                </Field>
                <label className="flex items-start gap-2 text-sm">
                    <input type="checkbox" className="mt-1" checked={form.data.allow_duplicates} onChange={(e) => form.setData('allow_duplicates', e.target.checked)} />
                    <span>Import possible duplicates too (rows whose phone or e-mail already belongs to a lead). Off by default.</span>
                </label>
                <div className="flex flex-wrap gap-2">
                    <Button type="button" variant="secondary" disabled={form.processing || !form.data.file} onClick={submit(true)}>
                        Check file
                    </Button>
                    <Button type="submit" disabled={form.processing || !form.data.file}>
                        {form.processing ? 'Working…' : 'Import'}
                    </Button>
                </div>
            </form>

            <div className="mt-8 max-w-3xl">
                <Card title="File format">
                    <p className="text-sm text-slate-600">
                        The first row must name the columns. Order does not matter and other columns are ignored, so a file exported from this CRM can be imported as is.
                    </p>
                    <table className="mt-3 text-left text-sm">
                        <tbody>
                            {COLUMNS.map(([name, help]) => (
                                <tr key={name} className="border-t border-slate-100">
                                    <td className="py-1 pr-4 font-mono">{name}</td>
                                    <td className="py-1 text-slate-600">{help}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </Card>
            </div>
        </AppLayout>
    );
}
