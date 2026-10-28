import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import AppLayout from '../../components/AppLayout';
import { PriorityBadge, StageBadge } from '../../components/Badges';
import TaskRow from '../../components/TaskRow';
import { Button, Card, EmptyState, ErrorBanner, Field, Select, Textarea, TextInput } from '../../components/ui';
import { formatDateTime, formatMoney, nowLocalInput, titleCase } from '../../lib/format';
import type { Lead, Option, SharedProps, Stage, Task, TimelineItem } from '../../types';

interface Props {
    lead: Lead;
    timeline: TimelineItem[];
    tasks: Task[];
    options: { stages: Stage[]; owners: Option[]; assignees: Option[]; activity_types: string[]; priorities: string[] };
    viewer_id: number;
}

function Detail({ label, children }: { label: string; children: React.ReactNode }) {
    return (
        <div>
            <dt className="text-xs uppercase tracking-wide text-slate-500">{label}</dt>
            <dd className="text-sm text-slate-900">{children}</dd>
        </div>
    );
}

function ActivityForm({ lead, types }: { lead: Lead; types: string[] }) {
    const form = useForm({ type: 'call', subject: '', body: '', occurred_at: '' });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (form.processing) return;
        form.post(`/leads/${lead.id}/activities`, { preserveScroll: true, onSuccess: () => form.reset('subject', 'body', 'occurred_at') });
    };
    return (
        <form onSubmit={submit} className="space-y-3" noValidate>
            <div className="grid gap-3 sm:grid-cols-2">
                <Field id="activity-type" label="Type" error={form.errors.type}>
                    <Select id="activity-type" value={form.data.type} error={form.errors.type} onChange={(e) => form.setData('type', e.target.value)}>
                        {types.map((t) => (
                            <option key={t} value={t}>
                                {titleCase(t)}
                            </option>
                        ))}
                    </Select>
                </Field>
                <Field id="activity-when" label="When (blank = now)" error={form.errors.occurred_at}>
                    <TextInput id="activity-when" type="datetime-local" value={form.data.occurred_at} error={form.errors.occurred_at} onChange={(e) => form.setData('occurred_at', e.target.value)} />
                </Field>
            </div>
            <Field id="activity-subject" label="Subject" error={form.errors.subject}>
                <TextInput id="activity-subject" maxLength={200} value={form.data.subject} error={form.errors.subject} onChange={(e) => form.setData('subject', e.target.value)} />
            </Field>
            <Field id="activity-body" label={form.data.type === 'note' ? 'Note' : 'Details'} error={form.errors.body}>
                <Textarea id="activity-body" rows={3} maxLength={5000} value={form.data.body} error={form.errors.body} onChange={(e) => form.setData('body', e.target.value)} />
            </Field>
            <Button type="submit" disabled={form.processing}>
                Record activity
            </Button>
        </form>
    );
}

function TaskForm({ lead, assignees, priorities, viewerId, timeZone }: { lead: Lead; assignees: Option[]; priorities: string[]; viewerId: number; timeZone: string }) {
    const form = useForm({ title: '', description: '', priority: 'medium', due_at: nowLocalInput(timeZone, 24), assigned_to: String(viewerId) });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (form.processing) return;
        form.post(`/leads/${lead.id}/tasks`, { preserveScroll: true, onSuccess: () => form.reset('title', 'description') });
    };
    return (
        <form onSubmit={submit} className="space-y-3" noValidate>
            <Field id="task-title" label="Follow-up" error={form.errors.title}>
                <TextInput id="task-title" maxLength={200} placeholder="e.g. Send quotation" value={form.data.title} error={form.errors.title} onChange={(e) => form.setData('title', e.target.value)} />
            </Field>
            <div className="grid gap-3 sm:grid-cols-3">
                <Field id="task-due" label="Due" error={form.errors.due_at}>
                    <TextInput id="task-due" type="datetime-local" value={form.data.due_at} error={form.errors.due_at} onChange={(e) => form.setData('due_at', e.target.value)} />
                </Field>
                <Field id="task-priority" label="Priority" error={form.errors.priority}>
                    <Select id="task-priority" value={form.data.priority} error={form.errors.priority} onChange={(e) => form.setData('priority', e.target.value)}>
                        {priorities.map((p) => (
                            <option key={p} value={p}>
                                {titleCase(p)}
                            </option>
                        ))}
                    </Select>
                </Field>
                {assignees.length > 1 && (
                    <Field id="task-assignee" label="Assign to" error={form.errors.assigned_to}>
                        <Select id="task-assignee" value={form.data.assigned_to} error={form.errors.assigned_to} onChange={(e) => form.setData('assigned_to', e.target.value)}>
                            {assignees.map((a) => (
                                <option key={a.id} value={a.id}>
                                    {a.name}
                                </option>
                            ))}
                        </Select>
                    </Field>
                )}
            </div>
            <Field id="task-description" label="Description (optional)" error={form.errors.description}>
                <Textarea id="task-description" rows={2} maxLength={5000} value={form.data.description} error={form.errors.description} onChange={(e) => form.setData('description', e.target.value)} />
            </Field>
            {form.errors.assigned_to && assignees.length <= 1 && <ErrorBanner message={form.errors.assigned_to} />}
            <Button type="submit" disabled={form.processing}>
                Schedule follow-up
            </Button>
        </form>
    );
}

export default function Show({ lead, timeline, tasks, options, viewer_id }: Props) {
    const { tenant } = usePage<SharedProps>().props;
    const tz = tenant?.timezone ?? 'Asia/Kolkata';
    const can = lead.can!;

    const changeStage = (stageId: string) => {
        router.patch(`/leads/${lead.id}/stage`, { pipeline_stage_id: stageId, expected_stage_id: lead.stage?.id ?? null }, { preserveScroll: true });
    };
    const changeOwner = (ownerId: string) => {
        router.patch(`/leads/${lead.id}/owner`, { owner_id: ownerId === '' ? null : ownerId }, { preserveScroll: true });
    };
    const archive = () => {
        if (window.confirm(`Archive ${lead.full_name}? It will be hidden from lists, the pipeline and follow-ups until restored.`)) {
            router.post(`/leads/${lead.id}/archive`);
        }
    };

    return (
        <AppLayout title={lead.full_name}>
            <Head title={lead.full_name} />
            <div className="space-y-4">
                {lead.archived_at && (
                    <div role="status" className="rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900">
                        This lead was archived on {formatDateTime(lead.archived_at, tz)} and is read-only.
                    </div>
                )}

                <div className="flex flex-wrap items-center gap-2">
                    <StageBadge stage={lead.stage} />
                    <PriorityBadge priority={lead.priority} />
                    <div className="ml-auto flex flex-wrap gap-2">
                        {can.update && (
                            <Link href={`/leads/${lead.id}/edit`} className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm hover:bg-slate-50">
                                Edit
                            </Link>
                        )}
                        {can.archive && (
                            <Button variant="danger" onClick={archive}>
                                Archive
                            </Button>
                        )}
                        {can.restore && (
                            <Button variant="secondary" onClick={() => router.post(`/leads/${lead.id}/restore`)}>
                                Restore
                            </Button>
                        )}
                    </div>
                </div>

                <div className="grid gap-4 lg:grid-cols-3">
                    <div className="space-y-4 lg:col-span-1">
                        <Card title="Details">
                            <dl className="space-y-3">
                                <Detail label="Phone">{lead.phone ?? '—'}</Detail>
                                <Detail label="E-mail">{lead.email ?? '—'}</Detail>
                                <Detail label="Company">{lead.company_name ?? '—'}</Detail>
                                <Detail label="Source">{lead.source?.name ?? '—'}</Detail>
                                <Detail label="Estimated value">{formatMoney(lead.estimated_value, tenant?.currency)}</Detail>
                                <Detail label="Last contacted">{formatDateTime(lead.last_contacted_at, tz)}</Detail>
                                <Detail label="Created">{formatDateTime(lead.created_at, tz)}</Detail>
                            </dl>
                        </Card>
                        <Card title="Pipeline & ownership">
                            <div className="space-y-3">
                                <Field id="stage-select" label="Stage">
                                    <Select id="stage-select" value={lead.stage?.id ?? ''} disabled={!can.change_stage} onChange={(e) => changeStage(e.target.value)}>
                                        {options.stages.map((s) => (
                                            <option key={s.id} value={s.id}>
                                                {s.name}
                                            </option>
                                        ))}
                                        {lead.stage && !options.stages.some((s) => s.id === lead.stage!.id) && <option value={lead.stage.id}>{lead.stage.name} (inactive)</option>}
                                    </Select>
                                </Field>
                                {can.assign ? (
                                    <Field id="owner-select" label="Owner">
                                        <Select id="owner-select" value={lead.owner?.id ?? ''} onChange={(e) => changeOwner(e.target.value)}>
                                            <option value="">Unassigned</option>
                                            {options.owners.map((o) => (
                                                <option key={o.id} value={o.id}>
                                                    {o.name}
                                                </option>
                                            ))}
                                        </Select>
                                    </Field>
                                ) : (
                                    <Detail label="Owner">{lead.owner?.name ?? 'Unassigned'}</Detail>
                                )}
                            </div>
                        </Card>
                    </div>

                    <div className="space-y-4 lg:col-span-2">
                        <Card title="Follow-ups">
                            {tasks.length === 0 ? <EmptyState>No follow-ups yet.</EmptyState> : <ul>{tasks.map((t) => <TaskRow key={t.id} task={t} showLead={false} />)}</ul>}
                            {can.create_task && (
                                <details className="mt-3" open={tasks.length === 0}>
                                    <summary className="cursor-pointer text-sm font-medium text-indigo-700">Schedule a follow-up</summary>
                                    <div className="mt-3">
                                        <TaskForm lead={lead} assignees={options.assignees} priorities={options.priorities} viewerId={viewer_id} timeZone={tz} />
                                    </div>
                                </details>
                            )}
                        </Card>

                        {can.log_activity && (
                            <Card title="Log an activity">
                                <ActivityForm lead={lead} types={options.activity_types} />
                            </Card>
                        )}

                        <Card title="Timeline">
                            {timeline.length === 0 ? (
                                <EmptyState>Nothing recorded yet.</EmptyState>
                            ) : (
                                <ol className="space-y-3">
                                    {timeline.map((item) => (
                                        <li key={item.id} className="border-l-2 border-slate-200 pl-3">
                                            {item.kind === 'stage' ? (
                                                <p className="text-sm">
                                                    <span className="font-medium">Stage:</span> {item.from ? `${item.from} → ${item.to}` : `Started in ${item.to}`}
                                                </p>
                                            ) : (
                                                <div className="text-sm">
                                                    <p>
                                                        <span className="font-medium">{titleCase(item.type)}</span>
                                                        {item.subject ? ` — ${item.subject}` : ''}
                                                    </p>
                                                    {item.body && <p className="mt-1 whitespace-pre-line text-slate-700">{item.body}</p>}
                                                </div>
                                            )}
                                            <p className="text-xs text-slate-500">
                                                {formatDateTime(item.at, tz)}
                                                {item.user ? ` · ${item.user}` : ''}
                                            </p>
                                        </li>
                                    ))}
                                </ol>
                            )}
                        </Card>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
