import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState, type DragEvent } from 'react';
import AppLayout from '../../components/AppLayout';
import { PriorityBadge } from '../../components/Badges';
import { EmptyState, Select } from '../../components/ui';
import { formatMoney } from '../../lib/format';
import type { PipelineCard, PipelineColumn, SharedProps, Stage } from '../../types';

interface Props {
    columns: PipelineColumn[];
    filters: { owner: string | null };
    options: { stages: Stage[]; can_filter_owner: boolean };
}

export default function Index({ columns, filters, options }: Props) {
    const { tenant } = usePage<SharedProps>().props;
    const [dragging, setDragging] = useState<PipelineCard | null>(null);
    const [over, setOver] = useState<number | null>(null);

    /** Server validates stage, tenant, permission and (via expected_stage_id) that nobody else moved it meanwhile. */
    const move = (card: PipelineCard, stageId: number) => {
        if (stageId === card.stage_id) return;
        router.patch(`/leads/${card.id}/stage`, { pipeline_stage_id: stageId, expected_stage_id: card.stage_id }, { preserveScroll: true });
    };

    const onDrop = (e: DragEvent, stageId: number) => {
        e.preventDefault();
        setOver(null);
        if (dragging && dragging.can_move) move(dragging, stageId);
        setDragging(null);
    };

    const filterOwner = (owner: string) => router.get('/pipeline', owner ? { owner } : {}, { preserveState: true, replace: true });

    return (
        <AppLayout title="Pipeline">
            <Head title="Pipeline" />
            <div className="mb-3 space-y-3">
                {options.can_filter_owner && (
                    <div className="max-w-xs">
                        <label htmlFor="board-owner" className="mb-1 block text-sm font-medium text-slate-700">
                            Show
                        </label>
                        <Select id="board-owner" value={filters.owner ?? ''} onChange={(e) => filterOwner(e.target.value)}>
                            <option value="">All leads</option>
                            <option value="me">My leads</option>
                            <option value="unassigned">Unassigned</option>
                        </Select>
                    </div>
                )}
            </div>

            {/* relative: contains the absolutely-positioned sr-only labels, otherwise they stretch the whole page on mobile */}
            <div className="relative flex snap-x gap-3 overflow-x-auto pb-4" role="list" aria-label="Pipeline stages">
                {columns.map((col) => (
                    <section
                        key={col.stage.id}
                        role="listitem"
                        aria-label={`${col.stage.name}, ${col.count} leads`}
                        onDragOver={(e) => { e.preventDefault(); setOver(col.stage.id); }}
                        onDragLeave={() => setOver((o) => (o === col.stage.id ? null : o))}
                        onDrop={(e) => onDrop(e, col.stage.id)}
                        className={`w-72 shrink-0 snap-start rounded-lg border p-2 ${over === col.stage.id ? 'border-indigo-500 bg-indigo-50' : 'border-slate-200 bg-slate-100'}`}
                    >
                        <header className="mb-2 flex items-center justify-between px-1">
                            <h2 className="text-sm font-semibold text-slate-800">
                                {col.stage.name}
                                {col.stage.inactive && <span className="ml-1 text-xs font-normal text-slate-500">(inactive)</span>}
                            </h2>
                            <span className="rounded-full bg-white px-2 py-0.5 text-xs text-slate-600">{col.count}</span>
                        </header>
                        <ul className="space-y-2">
                            {col.cards.length === 0 && (
                                <li>
                                    <EmptyState>No leads</EmptyState>
                                </li>
                            )}
                            {col.cards.map((card) => (
                                <li
                                    key={card.id}
                                    draggable={card.can_move}
                                    onDragStart={() => setDragging(card)}
                                    onDragEnd={() => { setDragging(null); setOver(null); }}
                                    className={`rounded-md border border-slate-200 bg-white p-2 shadow-sm ${card.can_move ? 'cursor-grab' : ''}`}
                                >
                                    <Link href={`/leads/${card.id}`} className="font-medium text-indigo-700 hover:underline">
                                        {card.full_name}
                                    </Link>
                                    {card.company_name && <p className="text-xs text-slate-500">{card.company_name}</p>}
                                    <div className="mt-1 flex flex-wrap items-center gap-1 text-xs">
                                        <PriorityBadge priority={card.priority} />
                                        {card.overdue_tasks > 0 && <span className="rounded bg-red-600 px-1.5 py-0.5 font-medium text-white">{card.overdue_tasks} overdue</span>}
                                        <span className="text-slate-600">{formatMoney(card.estimated_value, tenant?.currency)}</span>
                                    </div>
                                    <p className="mt-1 text-xs text-slate-500">{card.owner ?? 'Unassigned'}</p>
                                    {card.can_move && (
                                        <div className="mt-2">
                                            <label htmlFor={`move-${card.id}`} className="sr-only">
                                                Move {card.full_name} to stage
                                            </label>
                                            <Select id={`move-${card.id}`} value={card.stage_id} onChange={(e) => move(card, Number(e.target.value))}>
                                                {options.stages.map((s) => (
                                                    <option key={s.id} value={s.id}>
                                                        {s.name}
                                                    </option>
                                                ))}
                                                {!options.stages.some((s) => s.id === card.stage_id) && <option value={card.stage_id}>{col.stage.name}</option>}
                                            </Select>
                                        </div>
                                    )}
                                </li>
                            ))}
                        </ul>
                        {col.has_more && (
                            <p className="mt-2 px-1 text-xs text-slate-500">
                                Showing {col.cards.length} of {col.count}. <Link href={`/leads?stage=${col.stage.id}`} className="text-indigo-700 underline">View all</Link>
                            </p>
                        )}
                    </section>
                ))}
            </div>
        </AppLayout>
    );
}
