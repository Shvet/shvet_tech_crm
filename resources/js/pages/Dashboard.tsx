import { Head, Link, usePage } from '@inertiajs/react';
import AppLayout from '../components/AppLayout';
import TaskRow from '../components/TaskRow';
import { Card, EmptyState } from '../components/ui';
import { formatDateTime, formatMoney, titleCase } from '../lib/format';
import type { DashboardMetrics, SharedProps, Task } from '../types';

interface Props {
    metrics: DashboardMetrics;
    task_lists: { overdue: Task[]; today: Task[]; upcoming: Task[] };
}

function Stat({ label, value, tone = 'default', href }: { label: string; value: string | number; tone?: 'default' | 'danger'; href?: string }) {
    const body = (
        <div className="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
            <p className="text-xs uppercase tracking-wide text-slate-500">{label}</p>
            <p className={`mt-1 text-2xl font-semibold ${tone === 'danger' && Number(value) > 0 ? 'text-red-700' : 'text-slate-900'}`}>{value}</p>
        </div>
    );
    return href ? (
        <Link href={href} className="block hover:opacity-90">
            {body}
        </Link>
    ) : (
        body
    );
}

export default function Dashboard({ metrics, task_lists }: Props) {
    const tenant = usePage<SharedProps>().props.tenant;
    const { totals, stages, recent_activity, tasks } = metrics;
    const max = Math.max(1, ...stages.map((s) => s.count));

    return (
        <AppLayout title="Dashboard">
            <Head title="Dashboard" />
            <p className="mb-4 text-sm text-slate-600">
                {tenant?.name} · {tenant?.role}. Figures cover the leads you are allowed to see.
            </p>

            <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <Stat label="Total leads" value={totals.leads} href="/leads" />
                <Stat label="New this week" value={totals.new_this_week} />
                <Stat label="Open pipeline" value={formatMoney(totals.open_value, tenant?.currency)} />
                <Stat label="Won / Lost" value={`${totals.won} / ${totals.lost}`} />
                <Stat label="Overdue follow-ups" value={tasks.overdue} tone="danger" href="/tasks?tab=overdue&assignee=all" />
                <Stat label="Due today" value={tasks.today} href="/tasks?tab=today&assignee=all" />
                <Stat label="Upcoming" value={tasks.upcoming} href="/tasks?tab=upcoming&assignee=all" />
                {totals.unassigned !== null && <Stat label="Unassigned leads" value={totals.unassigned} href="/leads?owner=unassigned" />}
            </div>

            <div className="mt-4 grid gap-4 lg:grid-cols-2">
                <Card title="Leads by stage">
                    {totals.leads === 0 ? (
                        <EmptyState>No leads yet.</EmptyState>
                    ) : (
                        <ul className="space-y-2">
                            {stages.map((s) => (
                                <li key={s.id}>
                                    <div className="flex justify-between text-sm">
                                        <Link href={`/leads?stage=${s.id}`} className="text-indigo-700 hover:underline">
                                            {s.name}
                                            {s.inactive ? ' (inactive)' : ''}
                                        </Link>
                                        <span className="text-slate-600">
                                            {s.count} · {formatMoney(s.value, tenant?.currency)}
                                        </span>
                                    </div>
                                    <div className="mt-1 h-2 rounded bg-slate-100" role="presentation">
                                        <div className={`h-2 rounded ${s.outcome === 'won' ? 'bg-green-500' : s.outcome === 'lost' ? 'bg-slate-400' : 'bg-indigo-500'}`} style={{ width: `${(s.count / max) * 100}%` }} />
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                </Card>

                <Card title="Recent activity">
                    {recent_activity.length === 0 ? (
                        <EmptyState>No activity recorded yet.</EmptyState>
                    ) : (
                        <ul className="space-y-2 text-sm">
                            {recent_activity.map((a) => (
                                <li key={a.id}>
                                    <span className="font-medium">{titleCase(a.type)}</span> ·{' '}
                                    <Link href={`/leads/${a.lead.id}`} className="text-indigo-700 hover:underline">
                                        {a.lead.name}
                                    </Link>
                                    {a.subject ? ` — ${a.subject}` : ''}
                                    <div className="text-xs text-slate-500">
                                        {formatDateTime(a.occurred_at, tenant?.timezone)}
                                        {a.user ? ` · ${a.user}` : ''}
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                </Card>
            </div>

            <div className="mt-4 grid gap-4 lg:grid-cols-3">
                {(
                    [
                        ['overdue', 'Overdue follow-ups'],
                        ['today', 'Due today'],
                        ['upcoming', 'Upcoming follow-ups'],
                    ] as const
                ).map(([key, title]) => (
                    <Card key={key} title={title}>
                        {task_lists[key].length === 0 ? <EmptyState>None.</EmptyState> : <ul>{task_lists[key].map((t) => <TaskRow key={t.id} task={t} />)}</ul>}
                    </Card>
                ))}
            </div>
        </AppLayout>
    );
}
