import type { Priority, Stage } from '../types';
import { titleCase } from '../lib/format';

const PRIORITY: Record<Priority, string> = {
    low: 'bg-slate-100 text-slate-700',
    medium: 'bg-amber-100 text-amber-800',
    high: 'bg-red-100 text-red-800',
};

export function PriorityBadge({ priority }: { priority: Priority }) {
    return <span className={`inline-block rounded px-2 py-0.5 text-xs font-medium ${PRIORITY[priority]}`}>{titleCase(priority)}</span>;
}

const OUTCOME: Record<Stage['outcome'], string> = {
    open: 'bg-indigo-100 text-indigo-800',
    won: 'bg-green-100 text-green-800',
    lost: 'bg-slate-200 text-slate-700',
};

export function StageBadge({ stage }: { stage: Pick<Stage, 'name' | 'outcome'> | null }) {
    if (!stage) return <span className="text-slate-400">—</span>;
    return <span className={`inline-block rounded px-2 py-0.5 text-xs font-medium ${OUTCOME[stage.outcome]}`}>{stage.name}</span>;
}
