import { Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { formatDateTime } from '../lib/format';
import type { SharedProps, Task } from '../types';
import { PriorityBadge } from './Badges';
import { Button, Textarea } from './ui';

/** One follow-up, with inline complete (optional outcome) / reopen. Used on the lead page, task list and dashboard. */
export default function TaskRow({ task, showLead = true }: { task: Task; showLead?: boolean }) {
    const tz = usePage<SharedProps>().props.tenant?.timezone;
    const [completing, setCompleting] = useState(false);
    const [outcome, setOutcome] = useState('');
    const [busy, setBusy] = useState(false);
    const done = task.status === 'completed';

    const complete = () => {
        if (busy) return;
        setBusy(true);
        router.post(`/tasks/${task.id}/complete`, { outcome: outcome.trim() || null }, { preserveScroll: true, onFinish: () => { setBusy(false); setCompleting(false); } });
    };
    const reopen = () => {
        if (busy) return;
        setBusy(true);
        router.post(`/tasks/${task.id}/reopen`, {}, { preserveScroll: true, onFinish: () => setBusy(false) });
    };

    return (
        <li className="space-y-2 border-t border-slate-100 py-3 first:border-t-0">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div className="min-w-0">
                    <p className={`font-medium ${done ? 'text-slate-500 line-through' : 'text-slate-900'}`}>{task.title}</p>
                    {showLead && task.lead && (
                        <Link href={`/leads/${task.lead.id}`} className="text-sm text-indigo-700 hover:underline">
                            {task.lead.name}
                        </Link>
                    )}
                    {task.description && <p className="mt-1 whitespace-pre-line text-sm text-slate-600">{task.description}</p>}
                </div>
                <div className="flex flex-wrap items-center gap-2 text-sm">
                    <PriorityBadge priority={task.priority} />
                    {task.is_overdue && <span className="rounded bg-red-600 px-2 py-0.5 text-xs font-medium text-white">Overdue</span>}
                    <time dateTime={task.due_at} className={task.is_overdue ? 'font-medium text-red-700' : 'text-slate-600'}>
                        {done ? `Done ${formatDateTime(task.completed_at, tz)}` : `Due ${formatDateTime(task.due_at, tz)}`}
                    </time>
                    {task.assignee && <span className="text-slate-500">· {task.assignee.name}</span>}
                </div>
            </div>
            {done && task.outcome && <p className="text-sm text-slate-600">Outcome: {task.outcome}</p>}

            {task.can.complete && !done && !completing && (
                <Button variant="secondary" onClick={() => setCompleting(true)}>
                    Complete
                </Button>
            )}
            {task.can.complete && done && (
                <Button variant="secondary" onClick={reopen} disabled={busy}>
                    Reopen
                </Button>
            )}
            {completing && (
                <div className="space-y-2">
                    <label htmlFor={`outcome-${task.id}`} className="block text-sm font-medium text-slate-700">
                        Outcome (optional)
                    </label>
                    <Textarea id={`outcome-${task.id}`} rows={2} value={outcome} maxLength={2000} onChange={(e) => setOutcome(e.target.value)} />
                    <div className="flex gap-2">
                        <Button onClick={complete} disabled={busy}>
                            Mark complete
                        </Button>
                        <Button variant="secondary" onClick={() => setCompleting(false)} disabled={busy}>
                            Cancel
                        </Button>
                    </div>
                </div>
            )}
        </li>
    );
}
