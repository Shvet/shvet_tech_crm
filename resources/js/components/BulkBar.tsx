import { router } from '@inertiajs/react';
import { useState } from 'react';
import { Select } from './ui';
import type { Option, Stage } from '../types';

type Action = '' | 'assign' | 'stage' | 'archive';

/** Toolbar for the selected leads. The server re-checks every lead; this only collects the choice. */
export default function BulkBar({ ids, stages, owners, archived, onDone }: { ids: string[]; stages: Stage[]; owners: Option[]; archived: boolean; onDone: () => void }) {
    const [action, setAction] = useState<Action>('');
    const [owner, setOwner] = useState('');
    const [stage, setStage] = useState('');
    const [busy, setBusy] = useState(false);

    const ready = action === 'archive' || (action === 'assign' && owner !== '') || (action === 'stage' && stage !== '');

    const apply = () => {
        if (!ready || busy) return;
        if (action === 'archive' && !window.confirm(`Archive ${ids.length} lead(s)? They can be restored later.`)) return;
        const payload: Record<string, unknown> = { ids, action };
        if (action === 'assign') payload.owner_id = owner === 'none' ? null : Number(owner);
        if (action === 'stage') payload.pipeline_stage_id = Number(stage);
        router.post('/leads/bulk', payload as never, {
            preserveScroll: true,
            onStart: () => setBusy(true),
            onFinish: () => setBusy(false),
            onSuccess: () => {
                setAction('');
                onDone();
            },
        });
    };

    return (
        <div role="region" aria-label="Bulk actions" className="mb-3 flex flex-wrap items-center gap-2 rounded-md border border-indigo-200 bg-indigo-50 px-3 py-2 text-sm">
            <span className="font-medium">{ids.length} selected</span>
            <label htmlFor="bulk-action" className="sr-only">Action</label>
            <Select id="bulk-action" value={action} onChange={(e) => setAction(e.target.value as Action)}>
                <option value="">Choose an action…</option>
                {owners.length > 0 && <option value="assign">Assign owner</option>}
                {!archived && <option value="stage">Move to stage</option>}
                {!archived && <option value="archive">Archive</option>}
            </Select>
            {action === 'assign' && (
                <>
                    <label htmlFor="bulk-owner" className="sr-only">New owner</label>
                    <Select id="bulk-owner" value={owner} onChange={(e) => setOwner(e.target.value)}>
                        <option value="">Choose owner…</option>
                        <option value="none">Unassigned</option>
                        {owners.map((o) => (
                            <option key={o.id} value={o.id}>
                                {o.name}
                            </option>
                        ))}
                    </Select>
                </>
            )}
            {action === 'stage' && (
                <>
                    <label htmlFor="bulk-stage" className="sr-only">New stage</label>
                    <Select id="bulk-stage" value={stage} onChange={(e) => setStage(e.target.value)}>
                        <option value="">Choose stage…</option>
                        {stages.map((s) => (
                            <option key={s.id} value={s.id}>
                                {s.name}
                            </option>
                        ))}
                    </Select>
                </>
            )}
            <button type="button" disabled={!ready || busy} onClick={apply} className="rounded-md bg-indigo-600 px-3 py-1.5 font-medium text-white hover:bg-indigo-700 disabled:opacity-50">
                {busy ? 'Applying…' : 'Apply'}
            </button>
            <button type="button" onClick={onDone} className="px-1 text-indigo-700 hover:underline">
                Clear selection
            </button>
        </div>
    );
}
