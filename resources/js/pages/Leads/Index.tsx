import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import AppLayout from '../../components/AppLayout';
import { PriorityBadge, StageBadge } from '../../components/Badges';
import Pagination from '../../components/Pagination';
import { EmptyState, Field, Select, TextInput } from '../../components/ui';
import { formatDate, formatMoney } from '../../lib/format';
import { cleanQuery } from '../../lib/query';
import type { Lead, Option, Paginated, SharedProps, Stage } from '../../types';

interface Filters {
    q: string;
    stage: number | null;
    source: number | null;
    owner: string | null;
    priority: string | null;
    archived: boolean;
    sort: string;
    dir: 'asc' | 'desc';
}

interface Props {
    leads: Paginated<Lead>;
    filters: Filters;
    options: { stages: Stage[]; sources: { id: number; name: string }[]; owners: Option[]; priorities: string[] };
    can: { create: boolean; see_archived: boolean };
}

const COLUMNS: { label: string; sort?: string }[] = [
    { label: 'Name', sort: 'name' },
    { label: 'Contact' },
    { label: 'Stage' },
    { label: 'Owner' },
    { label: 'Priority' },
    { label: 'Value', sort: 'value' },
    { label: 'Last contact', sort: 'last_contacted' },
    { label: 'Created', sort: 'created' },
];

export default function Index({ leads, filters, options, can }: Props) {
    const tenant = usePage<SharedProps>().props.tenant;
    const [q, setQ] = useState(filters.q);

    const go = (changes: Partial<Record<keyof Filters, string | number | boolean | null>>) => {
        router.get('/leads', cleanQuery({ ...filters, q, ...changes }), { preserveState: true, replace: true });
    };
    const search = (e: FormEvent) => {
        e.preventDefault();
        go({});
    };
    const sortBy = (sort: string) => go({ sort, dir: filters.sort === sort && filters.dir === 'asc' ? 'desc' : 'asc' });
    const anyFilter = Boolean(filters.q || filters.stage || filters.source || filters.owner || filters.priority || filters.archived);

    return (
        <AppLayout title={filters.archived ? 'Archived leads' : 'Leads'}>
            <Head title="Leads" />
            <div className="mb-4 flex flex-wrap items-end justify-between gap-3">
                <form onSubmit={search} role="search" className="flex flex-wrap items-end gap-3">
                    <Field id="q" label="Search">
                        <TextInput id="q" type="search" placeholder="Name, company, e-mail or phone" value={q} onChange={(e) => setQ(e.target.value)} />
                    </Field>
                    <Field id="f-stage" label="Stage">
                        <Select id="f-stage" value={filters.stage ?? ''} onChange={(e) => go({ stage: e.target.value })}>
                            <option value="">All stages</option>
                            {options.stages.map((s) => (
                                <option key={s.id} value={s.id}>
                                    {s.name}
                                </option>
                            ))}
                        </Select>
                    </Field>
                    <Field id="f-source" label="Source">
                        <Select id="f-source" value={filters.source ?? ''} onChange={(e) => go({ source: e.target.value })}>
                            <option value="">All sources</option>
                            {options.sources.map((s) => (
                                <option key={s.id} value={s.id}>
                                    {s.name}
                                </option>
                            ))}
                        </Select>
                    </Field>
                    {options.owners.length > 0 && (
                        <Field id="f-owner" label="Owner">
                            <Select id="f-owner" value={filters.owner ?? ''} onChange={(e) => go({ owner: e.target.value })}>
                                <option value="">Anyone</option>
                                <option value="me">Me</option>
                                <option value="unassigned">Unassigned</option>
                                {options.owners.map((o) => (
                                    <option key={o.id} value={o.id}>
                                        {o.name}
                                    </option>
                                ))}
                            </Select>
                        </Field>
                    )}
                    <Field id="f-priority" label="Priority">
                        <Select id="f-priority" value={filters.priority ?? ''} onChange={(e) => go({ priority: e.target.value })}>
                            <option value="">Any</option>
                            {options.priorities.map((p) => (
                                <option key={p} value={p}>
                                    {p[0].toUpperCase() + p.slice(1)}
                                </option>
                            ))}
                        </Select>
                    </Field>
                    <button type="submit" className="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-700">
                        Search
                    </button>
                    {anyFilter && (
                        <button type="button" onClick={() => { setQ(''); router.get('/leads', {}, { replace: true }); }} className="px-1 py-2 text-sm text-indigo-700 hover:underline">
                            Clear filters
                        </button>
                    )}
                </form>
                <div className="flex gap-2">
                    {can.see_archived && (
                        <button type="button" onClick={() => go({ archived: !filters.archived })} className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm hover:bg-slate-50" aria-pressed={filters.archived}>
                            {filters.archived ? 'Show active' : 'Show archived'}
                        </button>
                    )}
                    {can.create && (
                        <Link href="/leads/create" className="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-700">
                            New lead
                        </Link>
                    )}
                </div>
            </div>

            {leads.data.length === 0 ? (
                <EmptyState>{anyFilter ? 'No leads match these filters.' : 'No leads yet. Create your first lead to get started.'}</EmptyState>
            ) : (
                <div className="overflow-x-auto rounded-md border border-slate-200 bg-white">
                    <table className="min-w-full text-left text-sm">
                        <thead className="bg-slate-50 text-slate-600">
                            <tr>
                                {COLUMNS.map((c) => (
                                    <th key={c.label} scope="col" className="px-3 py-2" aria-sort={c.sort && filters.sort === c.sort ? (filters.dir === 'asc' ? 'ascending' : 'descending') : undefined}>
                                        {c.sort ? (
                                            <button type="button" onClick={() => sortBy(c.sort!)} className="font-semibold hover:underline">
                                                {c.label}
                                                {filters.sort === c.sort ? (filters.dir === 'asc' ? ' ▲' : ' ▼') : ''}
                                            </button>
                                        ) : (
                                            c.label
                                        )}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {leads.data.map((l) => (
                                <tr key={l.id} className="border-t border-slate-100 align-top">
                                    <td className="px-3 py-2">
                                        <Link href={`/leads/${l.id}`} className="font-medium text-indigo-700 hover:underline">
                                            {l.full_name}
                                        </Link>
                                        {l.company_name && <div className="text-xs text-slate-500">{l.company_name}</div>}
                                    </td>
                                    <td className="px-3 py-2">
                                        <div>{l.phone ?? '—'}</div>
                                        <div className="text-xs text-slate-500">{l.email}</div>
                                    </td>
                                    <td className="px-3 py-2"><StageBadge stage={l.stage} /></td>
                                    <td className="px-3 py-2">{l.owner?.name ?? <span className="text-slate-400">Unassigned</span>}</td>
                                    <td className="px-3 py-2"><PriorityBadge priority={l.priority} /></td>
                                    <td className="px-3 py-2">{formatMoney(l.estimated_value, tenant?.currency)}</td>
                                    <td className="px-3 py-2">{formatDate(l.last_contacted_at, tenant?.timezone)}</td>
                                    <td className="px-3 py-2">{formatDate(l.created_at, tenant?.timezone)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
            <div className="mt-4">
                <Pagination page={leads} />
            </div>
        </AppLayout>
    );
}
