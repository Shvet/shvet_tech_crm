import { Head, router, usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import AppLayout from '../../components/AppLayout';
import { Card, EmptyState, Field, TextInput } from '../../components/ui';
import { formatMoney } from '../../lib/format';
import type { SharedProps } from '../../types';

interface Row {
    key: string;
    name: string;
    created: number;
    won: number;
    lost: number;
    won_value: string;
    open: number;
    open_value: string;
    win_rate: number | null;
}

interface Report {
    range: { from: string; to: string };
    summary: { created: number; won: number; lost: number; won_value: string; win_rate: number | null; avg_days_to_win: number | null };
    by_source: Row[];
    by_owner: Row[];
    funnel: { name: string; count: number; value: string }[];
}

const PRESETS: [string, string][] = [
    ['7', 'Last 7 days'],
    ['30', 'Last 30 days'],
    ['90', 'Last 90 days'],
    ['365', 'Last 12 months'],
];

function Stat({ label, value }: { label: string; value: string }) {
    return (
        <div className="rounded-md border border-slate-200 bg-white p-4">
            <dt className="text-sm text-slate-600">{label}</dt>
            <dd className="mt-1 text-2xl font-semibold">{value}</dd>
        </div>
    );
}

function Breakdown({ title, rows, currency }: { title: string; rows: Row[]; currency?: string }) {
    return (
        <Card title={title}>
            {rows.length === 0 ? (
                <p className="text-sm text-slate-500">No leads in this period.</p>
            ) : (
                <div className="overflow-x-auto">
                    <table className="min-w-full text-left text-sm">
                        <thead className="text-slate-600">
                            <tr>
                                <th scope="col" className="py-1 pr-4">{title.replace('By ', '')}</th>
                                <th scope="col" className="py-1 pr-4 text-right">Created</th>
                                <th scope="col" className="py-1 pr-4 text-right">Won</th>
                                <th scope="col" className="py-1 pr-4 text-right">Lost</th>
                                <th scope="col" className="py-1 pr-4 text-right">Win rate</th>
                                <th scope="col" className="py-1 pr-4 text-right">Won value</th>
                                <th scope="col" className="py-1 text-right">Open now</th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((r) => (
                                <tr key={r.key} className="border-t border-slate-100">
                                    <th scope="row" className="py-1 pr-4 font-medium">{r.name}</th>
                                    <td className="py-1 pr-4 text-right">{r.created}</td>
                                    <td className="py-1 pr-4 text-right">{r.won}</td>
                                    <td className="py-1 pr-4 text-right">{r.lost}</td>
                                    <td className="py-1 pr-4 text-right">{r.win_rate === null ? '—' : `${r.win_rate}%`}</td>
                                    <td className="py-1 pr-4 text-right">{formatMoney(r.won_value, currency)}</td>
                                    <td className="py-1 text-right">{r.open}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </Card>
    );
}

export default function Index({ report, preset }: { report: Report; preset: string }) {
    const currency = usePage<SharedProps>().props.tenant?.currency;
    const [from, setFrom] = useState(report.range.from);
    const [to, setTo] = useState(report.range.to);
    const s = report.summary;
    const maxFunnel = Math.max(1, ...report.funnel.map((f) => f.count));

    const custom = (e: FormEvent) => {
        e.preventDefault();
        router.get('/reports', { from, to }, { preserveState: true, replace: true });
    };

    return (
        <AppLayout title="Reports">
            <Head title="Reports" />
            <div className="mb-6 flex flex-wrap items-end gap-3">
                <nav aria-label="Report period" className="flex flex-wrap gap-2">
                    {PRESETS.map(([key, label]) => (
                        <button
                            key={key}
                            type="button"
                            aria-pressed={preset === key}
                            onClick={() => router.get('/reports', { range: key }, { preserveState: false, replace: true })}
                            className={`rounded-md border px-3 py-1.5 text-sm ${preset === key ? 'border-indigo-600 bg-indigo-600 text-white' : 'border-slate-300 bg-white hover:bg-slate-50'}`}
                        >
                            {label}
                        </button>
                    ))}
                </nav>
                <form onSubmit={custom} className="flex flex-wrap items-end gap-2">
                    <Field id="from" label="From">
                        <TextInput id="from" type="date" value={from} onChange={(e) => setFrom(e.target.value)} />
                    </Field>
                    <Field id="to" label="To">
                        <TextInput id="to" type="date" value={to} onChange={(e) => setTo(e.target.value)} />
                    </Field>
                    <button type="submit" className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm hover:bg-slate-50">
                        Show
                    </button>
                </form>
            </div>

            <p className="mb-3 text-sm text-slate-600">
                {report.range.from} to {report.range.to}. Won and lost count leads that moved to a won or lost stage in this period and are still there.
            </p>

            <dl className="mb-6 grid grid-cols-2 gap-3 md:grid-cols-3 lg:grid-cols-6">
                <Stat label="Leads created" value={String(s.created)} />
                <Stat label="Won" value={String(s.won)} />
                <Stat label="Lost" value={String(s.lost)} />
                <Stat label="Win rate" value={s.win_rate === null ? '—' : `${s.win_rate}%`} />
                <Stat label="Won value" value={formatMoney(s.won_value, currency)} />
                <Stat label="Avg. days to win" value={s.avg_days_to_win === null ? '—' : String(s.avg_days_to_win)} />
            </dl>

            {s.created === 0 && s.won === 0 && s.lost === 0 && report.funnel.every((f) => f.count === 0) ? (
                <EmptyState>No lead activity in this period yet.</EmptyState>
            ) : (
                <div className="space-y-6">
                    <Breakdown title="By source" rows={report.by_source} currency={currency} />
                    <Breakdown title="By owner" rows={report.by_owner} currency={currency} />
                    <Card title="Open pipeline now">
                        <ul aria-label="Open pipeline" className="space-y-2">
                            {report.funnel.map((f) => (
                                <li key={f.name} className="text-sm">
                                    <div className="flex justify-between">
                                        <span>{f.name}</span>
                                        <span>
                                            {f.count} · {formatMoney(f.value, currency)}
                                        </span>
                                    </div>
                                    <div className="mt-1 h-2 rounded bg-slate-100" aria-hidden="true">
                                        <div className="h-2 rounded bg-indigo-500" style={{ width: `${(f.count / maxFunnel) * 100}%` }} />
                                    </div>
                                </li>
                            ))}
                        </ul>
                    </Card>
                </div>
            )}
        </AppLayout>
    );
}
