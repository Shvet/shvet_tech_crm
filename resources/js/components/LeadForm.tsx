import { Link, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { titleCase } from '../lib/format';
import type { Lead, Option, SharedProps, Stage } from '../types';
import { Button, ErrorBanner, Field, Select, Textarea, TextInput } from './ui';

export interface LeadFormOptions {
    stages: Stage[];
    sources: { id: number; name: string }[];
    owners: Option[];
    priorities: string[];
    can_assign: boolean;
}

interface Props {
    mode: 'create' | 'edit';
    options: LeadFormOptions;
    lead?: Lead;
    submissionToken?: string;
}

/** Shared create / edit form. A submission token makes accidental double submits harmless on the server too. */
export default function LeadForm({ mode, options, lead, submissionToken }: Props) {
    const { flash, errors: pageErrors } = usePage<SharedProps>().props;
    const form = useForm({
        full_name: lead?.full_name ?? '',
        phone: lead?.phone ?? '',
        email: lead?.email ?? '',
        company_name: lead?.company_name ?? '',
        priority: lead?.priority ?? 'medium',
        estimated_value: lead?.estimated_value ?? '',
        lead_source_id: lead?.source ? String(lead.source.id) : options.sources.find((s) => s.name === 'Manual entry')?.id.toString() ?? '',
        pipeline_stage_id: '',
        owner_id: '',
        notes: '',
        submission_token: submissionToken ?? '',
        confirm_duplicate: false,
    });
    const { errors } = form;
    const duplicates = flash?.duplicates ?? [];
    const duplicateError = pageErrors?.duplicate;

    const submit = (confirm: boolean) => {
        if (form.processing) return;
        form.transform((d) => ({ ...d, confirm_duplicate: confirm }));
        if (mode === 'create') {
            form.post('/leads');
        } else if (lead) {
            form.patch(`/leads/${lead.id}`);
        }
    };
    const onSubmit = (e: FormEvent) => {
        e.preventDefault();
        submit(false);
    };

    return (
        <form onSubmit={onSubmit} className="max-w-2xl space-y-4" noValidate>
            {duplicateError && (
                <div role="alert" className="space-y-2 rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900">
                    <p className="font-medium">{duplicateError}</p>
                    <ul className="list-inside list-disc">
                        {duplicates.map((d, i) => (
                            <li key={i}>
                                {d.visible && d.id ? (
                                    <Link href={`/leads/${d.id}`} className="text-indigo-700 underline">
                                        {d.full_name}
                                    </Link>
                                ) : (
                                    <span>A lead you do not have access to</span>
                                )}{' '}
                                — same {d.matches.join(' and ')}
                                {d.archived ? ' (archived)' : ''}
                            </li>
                        ))}
                    </ul>
                    <Button type="button" variant="secondary" disabled={form.processing} onClick={() => submit(true)}>
                        {mode === 'create' ? 'Create anyway' : 'Save anyway'}
                    </Button>
                </div>
            )}
            <ErrorBanner message={pageErrors?.lead} />

            <Field id="full_name" label="Full name" error={errors.full_name}>
                <TextInput id="full_name" required maxLength={255} value={form.data.full_name} error={errors.full_name} onChange={(e) => form.setData('full_name', e.target.value)} />
            </Field>
            <div className="grid gap-4 sm:grid-cols-2">
                <Field id="phone" label="Phone" error={errors.phone}>
                    <TextInput id="phone" type="tel" autoComplete="off" placeholder="98765 43210 or +1 415 555 2671" value={form.data.phone} error={errors.phone} onChange={(e) => form.setData('phone', e.target.value)} />
                </Field>
                <Field id="email" label="E-mail" error={errors.email}>
                    <TextInput id="email" type="email" autoComplete="off" value={form.data.email} error={errors.email} onChange={(e) => form.setData('email', e.target.value)} />
                </Field>
            </div>
            <Field id="company_name" label="Company" error={errors.company_name}>
                <TextInput id="company_name" maxLength={255} value={form.data.company_name} error={errors.company_name} onChange={(e) => form.setData('company_name', e.target.value)} />
            </Field>
            <div className="grid gap-4 sm:grid-cols-3">
                <Field id="lead_source_id" label="Source" error={errors.lead_source_id}>
                    <Select id="lead_source_id" required value={form.data.lead_source_id} error={errors.lead_source_id} onChange={(e) => form.setData('lead_source_id', e.target.value)}>
                        <option value="">Choose…</option>
                        {options.sources.map((s) => (
                            <option key={s.id} value={s.id}>
                                {s.name}
                            </option>
                        ))}
                    </Select>
                </Field>
                <Field id="priority" label="Priority" error={errors.priority}>
                    <Select id="priority" value={form.data.priority} error={errors.priority} onChange={(e) => form.setData('priority', e.target.value as Lead['priority'])}>
                        {options.priorities.map((p) => (
                            <option key={p} value={p}>
                                {titleCase(p)}
                            </option>
                        ))}
                    </Select>
                </Field>
                <Field id="estimated_value" label="Estimated value (₹)" error={errors.estimated_value}>
                    <TextInput id="estimated_value" inputMode="decimal" value={form.data.estimated_value ?? ''} error={errors.estimated_value} onChange={(e) => form.setData('estimated_value', e.target.value)} />
                </Field>
            </div>

            {mode === 'create' && (
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field id="pipeline_stage_id" label="Starting stage" error={errors.pipeline_stage_id}>
                        <Select id="pipeline_stage_id" value={form.data.pipeline_stage_id} error={errors.pipeline_stage_id} onChange={(e) => form.setData('pipeline_stage_id', e.target.value)}>
                            <option value="">First stage (default)</option>
                            {options.stages.map((s) => (
                                <option key={s.id} value={s.id}>
                                    {s.name}
                                </option>
                            ))}
                        </Select>
                    </Field>
                    {options.can_assign && (
                        <Field id="owner_id" label="Owner" error={errors.owner_id}>
                            <Select id="owner_id" value={form.data.owner_id} error={errors.owner_id} onChange={(e) => form.setData('owner_id', e.target.value)}>
                                <option value="">Unassigned</option>
                                {options.owners.map((o) => (
                                    <option key={o.id} value={o.id}>
                                        {o.name}
                                    </option>
                                ))}
                            </Select>
                        </Field>
                    )}
                </div>
            )}
            {mode === 'create' && (
                <Field id="notes" label="First note (optional)" error={errors.notes}>
                    <Textarea id="notes" rows={3} maxLength={5000} value={form.data.notes} error={errors.notes} onChange={(e) => form.setData('notes', e.target.value)} />
                </Field>
            )}

            <div className="flex gap-3">
                <Button type="submit" disabled={form.processing} aria-busy={form.processing}>
                    {form.processing ? 'Saving…' : mode === 'create' ? 'Create lead' : 'Save changes'}
                </Button>
                <Link href={lead ? `/leads/${lead.id}` : '/leads'} className="inline-flex items-center rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-700 hover:bg-slate-50">
                    Cancel
                </Link>
            </div>
        </form>
    );
}
