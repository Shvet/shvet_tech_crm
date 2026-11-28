import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import AppLayout from '../../components/AppLayout';
import { Button, Card, ErrorBanner, Field, Select, TextInput } from '../../components/ui';
import type { SharedProps } from '../../types';

type Outcome = 'open' | 'won' | 'lost';

interface StageRow {
    id: number;
    name: string;
    key: string;
    outcome: Outcome;
    is_active: boolean;
    leads: number;
}

const OUTCOME_LABEL: Record<Outcome, string> = { open: 'Open (in progress)', won: 'Won', lost: 'Lost' };

function StageItem({ stage, index, count, onMove }: { stage: StageRow; index: number; count: number; onMove: (from: number, to: number) => void }) {
    const [name, setName] = useState(stage.name);
    const save = (changes: Record<string, unknown>) => router.patch(`/settings/pipeline/stages/${stage.id}`, changes as never, { preserveScroll: true });

    return (
        <li className={`flex flex-wrap items-center gap-2 border-t border-slate-100 py-2 ${stage.is_active ? '' : 'opacity-60'}`} aria-label={stage.name}>
            <span className="w-6 text-right text-sm text-slate-500">{index + 1}.</span>
            <label htmlFor={`stage-name-${stage.id}`} className="sr-only">
                Name of {stage.name}
            </label>
            <TextInput id={`stage-name-${stage.id}`} value={name} onChange={(e) => setName(e.target.value)} className="w-48" />
            {name.trim() !== stage.name && (
                <Button type="button" variant="secondary" onClick={() => save({ name })}>
                    Rename
                </Button>
            )}
            <label htmlFor={`stage-outcome-${stage.id}`} className="sr-only">
                Outcome of {stage.name}
            </label>
            <Select id={`stage-outcome-${stage.id}`} value={stage.outcome} onChange={(e) => save({ outcome: e.target.value })}>
                {Object.entries(OUTCOME_LABEL).map(([value, label]) => (
                    <option key={value} value={value}>
                        {label}
                    </option>
                ))}
            </Select>
            <span className="text-xs text-slate-500">{stage.leads === 1 ? '1 lead' : `${stage.leads} leads`}</span>
            <div className="ml-auto flex gap-1">
                <button type="button" aria-label={`Move ${stage.name} up`} disabled={index === 0} onClick={() => onMove(index, index - 1)} className="rounded border border-slate-300 px-2 py-1 text-sm disabled:opacity-40">
                    ↑
                </button>
                <button type="button" aria-label={`Move ${stage.name} down`} disabled={index === count - 1} onClick={() => onMove(index, index + 1)} className="rounded border border-slate-300 px-2 py-1 text-sm disabled:opacity-40">
                    ↓
                </button>
                <button type="button" onClick={() => save({ is_active: !stage.is_active })} className="rounded border border-slate-300 px-2 py-1 text-sm hover:bg-slate-50">
                    {stage.is_active ? 'Deactivate' : 'Reactivate'}
                </button>
            </div>
        </li>
    );
}

export default function Pipeline({ stages }: { stages: StageRow[] }) {
    const { errors } = usePage<SharedProps>().props;
    const form = useForm({ name: '', outcome: 'open' as Outcome });

    const move = (from: number, to: number) => {
        const ids = stages.map((s) => s.id);
        const [moved] = ids.splice(from, 1);
        ids.splice(to, 0, moved);
        router.put('/settings/pipeline/order', { ids }, { preserveScroll: true });
    };

    const add = (e: FormEvent) => {
        e.preventDefault();
        form.post('/settings/pipeline/stages', { preserveScroll: true, onSuccess: () => form.reset() });
    };

    return (
        <AppLayout title="Pipeline stages">
            <Head title="Pipeline stages" />
            <ErrorBanner message={errors?.ids ?? errors?.outcome ?? errors?.is_active} />
            <div className="max-w-3xl space-y-6">
                <Card title="Stages">
                    <p className="text-sm text-slate-600">
                        New leads start in the first active stage, so it must be an open stage. Deactivated stages disappear from forms and filters; leads already in them stay on the board until moved. Stages are never deleted, so history stays intact.
                    </p>
                    <ol aria-label="Pipeline stages" className="mt-3">
                        {stages.map((s, i) => (
                            <StageItem key={`${s.id}-${s.name}`} stage={s} index={i} count={stages.length} onMove={move} />
                        ))}
                    </ol>
                </Card>

                <Card title="Add a stage">
                    <form onSubmit={add} className="flex flex-wrap items-end gap-3" noValidate>
                        <Field id="new-stage-name" label="Name" error={form.errors.name}>
                            <TextInput id="new-stage-name" value={form.data.name} error={form.errors.name} onChange={(e) => form.setData('name', e.target.value)} />
                        </Field>
                        <Field id="new-stage-outcome" label="Outcome" error={form.errors.outcome}>
                            <Select id="new-stage-outcome" value={form.data.outcome} onChange={(e) => form.setData('outcome', e.target.value as Outcome)}>
                                {Object.entries(OUTCOME_LABEL).map(([value, label]) => (
                                    <option key={value} value={value}>
                                        {label}
                                    </option>
                                ))}
                            </Select>
                        </Field>
                        <Button type="submit" disabled={form.processing || form.data.name.trim() === ''}>
                            Add stage
                        </Button>
                    </form>
                </Card>
            </div>
        </AppLayout>
    );
}
