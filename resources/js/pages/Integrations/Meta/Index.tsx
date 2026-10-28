import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '../../../components/AppLayout';
import { Button, Select, TextInput } from '../../../components/ui';
import { can } from '../../../lib/permissions';
import type { MetaConnectionRow, MetaEventRow, MetaFormRow, MetaPageRow, MetaSetup, Option, SharedProps } from '../../../types';

interface Props {
    setup: MetaSetup;
    connections: MetaConnectionRow[];
    pages: MetaPageRow[];
    counts: Record<string, number>;
    events: MetaEventRow[];
    options: { sources: Option[]; stages: Option[]; members: Option[] };
}

const BASE = '/integrations/meta';
const keep = { preserveScroll: true } as const;

const STATUS_LABEL: Record<string, string> = {
    pending_selection: 'Choose Pages',
    active: 'Connected',
    reauthorization_required: 'Reconnect required',
    disconnected: 'Disconnected',
    received: 'Queued',
    processing: 'Importing',
    imported: 'Imported',
    needs_review: 'Needs review',
    dismissed: 'Dismissed',
    ignored: 'No mapping',
    failed: 'Failed',
    quarantined: 'Unknown Page',
};

function fmt(iso: string | null) {
    return iso ? new Date(iso).toLocaleString('en-IN', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'UTC' }) + ' UTC' : '—';
}

export default function Index({ setup, connections, pages, counts, events, options }: Props) {
    const { tenant } = usePage<SharedProps>().props;
    if (!can(tenant?.permissions, 'integrations.manage')) {
        return (
            <AppLayout title="Meta Lead Ads">
                <p role="alert">You do not have access to integrations.</p>
            </AppLayout>
        );
    }

    return (
        <AppLayout title="Meta Lead Ads">
            <Head title="Meta Lead Ads" />
            <div className="space-y-8">
                <p className="max-w-2xl text-sm text-slate-600">
                    Import leads from Facebook and Instagram Lead Ads into this workspace. Leads arrive in near real time, are checked against your existing leads, and are never merged or overwritten automatically.
                </p>

                <SetupSection setup={setup} hasConnection={connections.length > 0} />

                {connections.map((c) => (
                    <ConnectionSection key={c.id} connection={c} pages={pages.filter((p) => p.connection_id === c.id)} options={options} />
                ))}

                <HistorySection counts={counts} events={events} />
            </div>
        </AppLayout>
    );
}

function SetupSection({ setup, hasConnection }: { setup: MetaSetup; hasConnection: boolean }) {
    return (
        <section aria-labelledby="setup-h" className="space-y-3 rounded-md border border-slate-200 bg-white p-4">
            <h2 id="setup-h" className="text-lg font-medium">
                Connection
            </h2>
            {!setup.configured ? (
                <div role="alert" className="rounded border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900">
                    <p className="font-medium">Meta is not configured on this server yet.</p>
                    <p>Ask the platform operator to set: {setup.missing.join(', ')}. Setup steps are in docs/PHASE_3_META_LEAD_ADS.md.</p>
                </div>
            ) : (
                <>
                    <p className="text-sm text-slate-600">
                        Connect a Facebook account that manages your Pages. You will be asked to approve: <span className="font-mono text-xs">{setup.permissions.join(', ')}</span>. We keep only the Page access tokens (encrypted), never your Facebook password.
                    </p>
                    <Button onClick={() => router.post(`${BASE}/connect`)}>{hasConnection ? 'Connect another account / reconnect' : 'Connect with Facebook'}</Button>
                </>
            )}
            <details className="text-sm text-slate-600">
                <summary className="cursor-pointer">Setup details for your Meta administrator</summary>
                <dl className="mt-2 space-y-1">
                    <div>
                        <dt className="inline font-medium">Webhook callback URL: </dt>
                        <dd className="inline font-mono text-xs">{setup.webhook_url}</dd>
                    </div>
                    {setup.redirect_uri && (
                        <div>
                            <dt className="inline font-medium">OAuth redirect URI: </dt>
                            <dd className="inline font-mono text-xs">{setup.redirect_uri}</dd>
                        </div>
                    )}
                </dl>
            </details>
        </section>
    );
}

function ConnectionSection({ connection, pages, options }: { connection: MetaConnectionRow; pages: MetaPageRow[]; options: Props['options'] }) {
    const [picked, setPicked] = useState<string[]>([]);
    const needsReauth = connection.status === 'reauthorization_required';

    const disconnect = () => {
        if (window.confirm('Disconnect this Meta account? Stored access is deleted and imports stop. Existing leads are kept.')) {
            router.delete(`${BASE}/connections/${connection.id}`, keep);
        }
    };

    return (
        <section aria-label={`Meta account ${connection.account_name ?? ''}`} className="space-y-4 rounded-md border border-slate-200 bg-white p-4">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <h2 className="text-lg font-medium">{connection.account_name ?? 'Meta account'}</h2>
                    <p className="text-sm">
                        <span className={`rounded px-2 py-0.5 text-xs font-medium ${needsReauth ? 'bg-red-100 text-red-800' : 'bg-slate-100 text-slate-700'}`}>{STATUS_LABEL[connection.status]}</span>
                        <span className="ml-2 text-slate-500">Last checked {fmt(connection.last_validated_at)} · Last import {fmt(connection.last_sync_at)}</span>
                    </p>
                </div>
                <div className="space-x-2">
                    <Button variant="secondary" onClick={() => router.post(`${BASE}/connections/${connection.id}/validate`, {}, keep)}>
                        Check connection
                    </Button>
                    <Button variant="danger" onClick={disconnect}>
                        Disconnect
                    </Button>
                </div>
            </div>

            {needsReauth && (
                <div role="alert" className="rounded border border-red-300 bg-red-50 p-3 text-sm text-red-900">
                    <p className="font-medium">Imports are paused for this account.</p>
                    <p>{connection.error ?? 'Meta no longer accepts the saved authorization.'} Use “Connect another account / reconnect” above to sign in again; your mappings are kept.</p>
                </div>
            )}

            {connection.pending_pages.length > 0 && (
                <fieldset className="space-y-2 rounded border border-indigo-200 bg-indigo-50 p-3">
                    <legend className="px-1 text-sm font-medium">Choose Pages to import leads from</legend>
                    {connection.pending_pages.map((p) => (
                        <label key={p.id} className="flex items-center gap-2 text-sm">
                            <input type="checkbox" checked={picked.includes(p.id)} onChange={(e) => setPicked(e.target.checked ? [...picked, p.id] : picked.filter((x) => x !== p.id))} />
                            {p.name}
                        </label>
                    ))}
                    <Button disabled={picked.length === 0} onClick={() => router.post(`${BASE}/connections/${connection.id}/pages`, { pages: picked }, keep)}>
                        Connect selected Pages
                    </Button>
                </fieldset>
            )}

            {pages.map((p) => (
                <PageCard key={p.id} page={p} options={options} />
            ))}
            {pages.length === 0 && connection.pending_pages.length === 0 && <p className="text-sm text-slate-500">No Pages connected.</p>}
        </section>
    );
}

function PageCard({ page, options }: { page: MetaPageRow; options: Props['options'] }) {
    const disconnect = () => {
        if (window.confirm(`Disconnect the Page “${page.name}”? Imports from its forms stop. Existing leads are kept.`)) {
            router.delete(`${BASE}/pages/${page.id}`, keep);
        }
    };

    return (
        <article aria-label={`Page ${page.name}`} className="space-y-3 rounded border border-slate-200 p-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h3 className="font-medium">{page.name}</h3>
                <div className="space-x-2">
                    <Button variant="secondary" onClick={() => router.post(`${BASE}/pages/${page.id}/sync-forms`, {}, keep)}>
                        Refresh forms
                    </Button>
                    <Button variant="secondary" onClick={disconnect}>
                        Disconnect Page
                    </Button>
                </div>
            </div>
            {page.subscription_status !== 'subscribed' && (
                <div role="alert" className="rounded border border-amber-300 bg-amber-50 p-2 text-sm text-amber-900">
                    New leads will not arrive until the Page is subscribed to webhooks. {page.subscription_error}{' '}
                    <button type="button" className="underline" onClick={() => router.post(`${BASE}/pages/${page.id}/subscribe`, {}, keep)}>
                        Retry subscription
                    </button>
                </div>
            )}
            {page.forms.length === 0 && <p className="text-sm text-slate-500">No lead forms found yet. Use “Refresh forms”.</p>}
            {page.forms.map((f) => (
                <FormMapping key={f.id} form={f} options={options} />
            ))}
        </article>
    );
}

const FIELD_TARGETS: [string, string][] = [
    ['full_name', 'Full name'],
    ['email', 'E-mail'],
    ['phone', 'Phone'],
    ['company_name', 'Company'],
];

function FormMapping({ form, options }: { form: MetaFormRow; options: Props['options'] }) {
    const [state, setState] = useState({
        is_enabled: form.enabled,
        lead_source_id: form.lead_source_id ?? ('' as number | ''),
        pipeline_stage_id: form.pipeline_stage_id ?? ('' as number | ''),
        default_owner_id: form.default_owner_id ?? ('' as number | ''),
        field_map: { ...form.field_map } as Record<string, string>,
    });
    const id = (k: string) => `${form.id}-${k}`;
    const incomplete = state.is_enabled && (state.lead_source_id === '' || state.pipeline_stage_id === '');

    const save = () =>
        router.put(
            `${BASE}/mappings/${form.id}`,
            {
                is_enabled: state.is_enabled,
                lead_source_id: state.lead_source_id === '' ? null : Number(state.lead_source_id),
                pipeline_stage_id: state.pipeline_stage_id === '' ? null : Number(state.pipeline_stage_id),
                default_owner_id: state.default_owner_id === '' ? null : Number(state.default_owner_id),
                field_map: state.field_map,
            },
            keep,
        );

    const numeric = (v: string) => (v === '' ? '' : Number(v));

    return (
        <form
            aria-label={`Form ${form.name}`}
            onSubmit={(e) => {
                e.preventDefault();
                save();
            }}
            className="space-y-3 rounded bg-slate-50 p-3"
        >
            <div className="flex flex-wrap items-center justify-between gap-2">
                <p className="font-medium">
                    {form.name} {form.status && <span className="text-xs font-normal text-slate-500">({form.status})</span>}
                </p>
                <label className="flex items-center gap-2 text-sm">
                    <input type="checkbox" checked={state.is_enabled} onChange={(e) => setState({ ...state, is_enabled: e.target.checked })} />
                    Import leads from this form
                </label>
            </div>
            <div className="grid gap-3 md:grid-cols-3">
                <div>
                    <label htmlFor={id('source')} className="block text-sm font-medium">
                        Lead source
                    </label>
                    <Select id={id('source')} value={state.lead_source_id} onChange={(e) => setState({ ...state, lead_source_id: numeric(e.target.value) })}>
                        <option value="">Choose…</option>
                        {options.sources.map((s) => (
                            <option key={s.id} value={s.id}>
                                {s.name}
                            </option>
                        ))}
                    </Select>
                </div>
                <div>
                    <label htmlFor={id('stage')} className="block text-sm font-medium">
                        Starting stage
                    </label>
                    <Select id={id('stage')} value={state.pipeline_stage_id} onChange={(e) => setState({ ...state, pipeline_stage_id: numeric(e.target.value) })}>
                        <option value="">Choose…</option>
                        {options.stages.map((s) => (
                            <option key={s.id} value={s.id}>
                                {s.name}
                            </option>
                        ))}
                    </Select>
                </div>
                <div>
                    <label htmlFor={id('owner')} className="block text-sm font-medium">
                        Default owner
                    </label>
                    <Select id={id('owner')} value={state.default_owner_id} onChange={(e) => setState({ ...state, default_owner_id: numeric(e.target.value) })}>
                        <option value="">Unassigned</option>
                        {options.members.map((m) => (
                            <option key={m.id} value={m.id}>
                                {m.name}
                            </option>
                        ))}
                    </Select>
                </div>
            </div>
            <details className="text-sm">
                <summary className="cursor-pointer">Custom question keys (optional)</summary>
                <p className="mt-1 text-slate-500">Only needed if your form uses custom question keys instead of Meta’s standard full_name / email / phone_number / company_name.</p>
                <div className="mt-2 grid gap-2 md:grid-cols-2">
                    {FIELD_TARGETS.map(([key, label]) => (
                        <div key={key}>
                            <label htmlFor={id(`map-${key}`)} className="block text-sm font-medium">
                                {label} question key
                            </label>
                            <TextInput id={id(`map-${key}`)} value={state.field_map[key] ?? ''} maxLength={100} onChange={(e) => setState({ ...state, field_map: { ...state.field_map, [key]: e.target.value } })} />
                        </div>
                    ))}
                </div>
            </details>
            {incomplete && (
                <p role="alert" className="text-sm text-amber-800">
                    Choose a lead source and a starting stage before enabling this form.
                </p>
            )}
            <div className="flex items-center gap-3">
                <Button type="submit" disabled={incomplete}>
                    Save mapping
                </Button>
                <span className="text-xs text-slate-500">Last import {fmt(form.last_import_at)}</span>
            </div>
        </form>
    );
}

function HistorySection({ counts, events }: { counts: Record<string, number>; events: MetaEventRow[] }) {
    const act = (id: string, action: 'retry' | 'create-anyway' | 'dismiss') => router.post(`${BASE}/events/${id}/${action}`, {}, keep);
    const shown = ['imported', 'needs_review', 'failed', 'ignored', 'received', 'quarantined'];

    return (
        <section aria-labelledby="history-h" className="space-y-3 rounded-md border border-slate-200 bg-white p-4">
            <h2 id="history-h" className="text-lg font-medium">
                Sync history
            </h2>
            <dl className="flex flex-wrap gap-4 text-sm">
                {shown.map((s) => (
                    <div key={s}>
                        <dt className="text-slate-500">{STATUS_LABEL[s]}</dt>
                        <dd className="text-lg font-semibold" data-testid={`count-${s}`}>
                            {counts[s] ?? 0}
                        </dd>
                    </div>
                ))}
            </dl>
            {events.length === 0 ? (
                <p className="text-sm text-slate-500">No leads received yet.</p>
            ) : (
                <div className="overflow-x-auto">
                    <table className="min-w-full text-left text-sm">
                        <thead className="bg-slate-50 text-slate-600">
                            <tr>
                                <th className="px-3 py-2">Received</th>
                                <th className="px-3 py-2">Status</th>
                                <th className="px-3 py-2">Details</th>
                                <th className="px-3 py-2">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {events.map((e) => (
                                <tr key={e.id} className="border-t border-slate-100 align-top">
                                    <td className="px-3 py-2">{fmt(e.received_at)}</td>
                                    <td className="px-3 py-2">{STATUS_LABEL[e.status]}</td>
                                    <td className="px-3 py-2">
                                        {e.failure && <p>{e.failure}</p>}
                                        {e.status === 'needs_review' && <p>Possible duplicate of an existing lead. Nothing was created.</p>}
                                        {e.status === 'ignored' && <p>This form has no enabled mapping.</p>}
                                        {e.warnings.length > 0 && <p className="text-xs text-slate-500">Notes: {e.warnings.join(', ').replaceAll('_', ' ')}</p>}
                                        {e.lead_id && (
                                            <a className="text-indigo-700 hover:underline" href={`/leads/${e.lead_id}`}>
                                                Open lead
                                            </a>
                                        )}
                                    </td>
                                    <td className="space-x-2 px-3 py-2">
                                        {e.replayable && (
                                            <Button variant="secondary" onClick={() => act(e.id, 'retry')}>
                                                Retry
                                            </Button>
                                        )}
                                        {e.reviewable && (
                                            <>
                                                <Button variant="secondary" onClick={() => act(e.id, 'create-anyway')}>
                                                    Create anyway
                                                </Button>
                                                <Button variant="secondary" onClick={() => act(e.id, 'dismiss')}>
                                                    Dismiss
                                                </Button>
                                            </>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </section>
    );
}
