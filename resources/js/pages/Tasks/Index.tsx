import { Head, Link, router } from '@inertiajs/react';
import AppLayout from '../../components/AppLayout';
import Pagination from '../../components/Pagination';
import TaskRow from '../../components/TaskRow';
import { EmptyState, Select } from '../../components/ui';
import { cleanQuery } from '../../lib/query';
import type { Option, Paginated, Task } from '../../types';

interface Props {
    tasks: Paginated<Task>;
    tab: 'overdue' | 'today' | 'upcoming' | 'completed';
    assignee: string;
    counts: { overdue: number; today: number; upcoming: number };
    options: { members: Option[] };
}

const TABS: { key: Props['tab']; label: string }[] = [
    { key: 'overdue', label: 'Overdue' },
    { key: 'today', label: 'Due today' },
    { key: 'upcoming', label: 'Upcoming' },
    { key: 'completed', label: 'Completed' },
];

const EMPTY: Record<Props['tab'], string> = {
    overdue: 'Nothing overdue. Nice work.',
    today: 'No follow-ups left for today.',
    upcoming: 'No upcoming follow-ups scheduled.',
    completed: 'No completed follow-ups yet.',
};

export default function Index({ tasks, tab, assignee, counts, options }: Props) {
    const href = (t: string, a: string = assignee) => `/tasks?${new URLSearchParams(cleanQuery({ tab: t, assignee: a === 'me' ? '' : a })).toString()}`;

    return (
        <AppLayout title="Follow-ups">
            <Head title="Follow-ups" />
            <div className="mb-4 flex flex-wrap items-end justify-between gap-3">
                <nav aria-label="Follow-up views" className="flex flex-wrap gap-2">
                    {TABS.map((t) => (
                        <Link
                            key={t.key}
                            href={href(t.key)}
                            aria-current={tab === t.key ? 'page' : undefined}
                            className={`rounded-md border px-3 py-1.5 text-sm ${tab === t.key ? 'border-indigo-600 bg-indigo-600 text-white' : 'border-slate-300 bg-white hover:bg-slate-50'}`}
                        >
                            {t.label}
                            {t.key !== 'completed' && (
                                <span className={`ml-2 rounded-full px-1.5 text-xs ${tab === t.key ? 'bg-white/20' : t.key === 'overdue' && counts.overdue > 0 ? 'bg-red-100 text-red-800' : 'bg-slate-100'}`}>
                                    {counts[t.key as 'overdue' | 'today' | 'upcoming']}
                                </span>
                            )}
                        </Link>
                    ))}
                </nav>
                <div className="w-56">
                    <label htmlFor="assignee" className="mb-1 block text-sm font-medium text-slate-700">
                        Assigned to
                    </label>
                    <Select id="assignee" value={assignee} onChange={(e) => router.get('/tasks', cleanQuery({ tab, assignee: e.target.value === 'me' ? '' : e.target.value }), { preserveState: true, replace: true })}>
                        <option value="me">Me</option>
                        <option value="all">Everyone</option>
                        {options.members.map((m) => (
                            <option key={m.id} value={m.id}>
                                {m.name}
                            </option>
                        ))}
                    </Select>
                </div>
            </div>

            {tasks.data.length === 0 ? (
                <EmptyState>{EMPTY[tab]}</EmptyState>
            ) : (
                <ul className="rounded-lg border border-slate-200 bg-white px-4">
                    {tasks.data.map((t) => (
                        <TaskRow key={t.id} task={t} />
                    ))}
                </ul>
            )}
            <div className="mt-4">
                <Pagination page={tasks} />
            </div>
        </AppLayout>
    );
}
