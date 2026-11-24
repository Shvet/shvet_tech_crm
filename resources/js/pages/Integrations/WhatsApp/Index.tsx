import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import AppLayout from '../../../components/AppLayout';
import { Button, Card, EmptyState, Field, Select, TextInput } from '../../../components/ui';
import { formatDateTime } from '../../../lib/format';
import { can } from '../../../lib/permissions';
import type { Option, SharedProps, WhatsAppTemplateRow } from '../../../types';

interface Account {
    id: string;
    phone_number_id: string;
    waba_id: string;
    display_phone_number: string | null;
    verified_name: string | null;
    status: 'active' | 'reauthorization_required' | 'disconnected';
    subscription_status: string;
    auto_create_leads: boolean;
    default_owner_id: number | null;
    last_validated_at: string | null;
    last_webhook_at: string | null;
    templates_synced_at: string | null;
    error: string | null;
}

interface Props {
    setup: { configured: boolean; missing: string[]; webhook_url: string; app_id: string | null };
    account: Account | null;
    templates: WhatsAppTemplateRow[];
    members: Option[];
}

const BASE = '/integrations/whatsapp';
const keep = { preserveScroll: true } as const;

export default function Index({ setup, account, templates, members }: Props) {
    const { tenant } = usePage<SharedProps>().props;
    const tz = tenant?.timezone;
    if (!can(tenant?.permissions, 'integrations.manage')) {
        return (
            <AppLayout title="WhatsApp setup">
                <p role="alert">You do not have access to integrations.</p>
            </AppLayout>
        );
    }

    return (
        <AppLayout title="WhatsApp setup">
            <Head title="WhatsApp setup" />
            <div className="max-w-3xl space-y-6">
                <p className="text-sm text-slate-600">
                    Connect your WhatsApp Business number so your team can reply to customers from the shared inbox. New chats are matched to existing leads by phone number; unknown numbers can become new leads automatically.
                </p>

                {!setup.configured && (
                    <div role="alert" className="rounded border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900">
                        <p className="font-medium">WhatsApp is not configured on this server yet.</p>
                        <p>Ask the platform operator to set: {setup.missing.join(', ')}. Setup steps are in docs/PHASE_4_WHATSAPP.md.</p>
                    </div>
                )}

                <Card title="Webhook">
                    <p className="text-sm text-slate-600">In your Meta app, under WhatsApp, then Configuration, set this callback URL and subscribe to the "messages" field:</p>
                    <code className="mt-2 block break-all rounded bg-slate-100 p-2 text-sm">{setup.webhook_url}</code>
                </Card>

                {account ? <Connected account={account} members={members} timeZone={tz} /> : <ConnectForm members={members} disabled={!setup.configured} />}

                {account && (
                    <Card
                        title="Message templates"
                        actions={
                            account.status === 'active' && (
                                <Button variant="secondary" onClick={() => router.post(`${BASE}/${account.id}/templates`, {}, keep)}>
                                    Refresh from Meta
                                </Button>
                            )
                        }
                    >
                        <p className="mb-2 text-xs text-slate-500">Last refreshed: {formatDateTime(account.templates_synced_at, tz)}. Templates are created and approved in WhatsApp Manager; only approved ones can be sent.</p>
                        {templates.length === 0 ? (
                            <EmptyState>No templates found.</EmptyState>
                        ) : (
                            <ul className="divide-y divide-slate-200 text-sm">
                                {templates.map((t) => (
                                    <li key={t.id} className="py-2">
                                        <p className="flex flex-wrap items-center gap-2">
                                            <span className="font-medium">{t.name}</span>
                                            <span className="text-slate-500">{t.language}</span>
                                            <span className={`rounded px-1.5 text-xs ${t.status === 'APPROVED' ? 'bg-green-100 text-green-800' : 'bg-slate-100 text-slate-700'}`}>{t.status}</span>
                                        </p>
                                        {t.body && <p className="text-slate-600">{t.body}</p>}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Card>
                )}
            </div>
        </AppLayout>
    );
}

function ConnectForm({ members, disabled }: { members: Option[]; disabled: boolean }) {
    const form = useForm({ waba_id: '', phone_number_id: '', access_token: '', auto_create_leads: true, default_owner_id: '' });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform((d) => ({ ...d, default_owner_id: d.default_owner_id === '' ? null : Number(d.default_owner_id) }));
        form.post(BASE, { ...keep, onSuccess: () => form.reset('access_token') });
    };

    return (
        <Card title="Connect a number">
            <form onSubmit={submit} className="space-y-4">
                <p className="text-sm text-slate-600">
                    In Meta Business Settings, create a System User with the <strong>whatsapp_business_management</strong> and <strong>whatsapp_business_messaging</strong> permissions on your WhatsApp account, generate a token, and copy the two ids from WhatsApp Manager.
                </p>
                <Field label="WhatsApp Business Account id" id="waba_id" error={form.errors.waba_id}>
                    <TextInput id="waba_id" inputMode="numeric" value={form.data.waba_id} onChange={(e) => form.setData('waba_id', e.target.value)} error={form.errors.waba_id} required />
                </Field>
                <Field label="Phone number id" id="phone_number_id" error={form.errors.phone_number_id}>
                    <TextInput id="phone_number_id" inputMode="numeric" value={form.data.phone_number_id} onChange={(e) => form.setData('phone_number_id', e.target.value)} error={form.errors.phone_number_id} required />
                </Field>
                <Field label="System User access token" id="access_token" error={form.errors.access_token}>
                    <TextInput id="access_token" type="password" autoComplete="off" value={form.data.access_token} onChange={(e) => form.setData('access_token', e.target.value)} error={form.errors.access_token} required />
                </Field>
                <LeadSettings members={members} auto={form.data.auto_create_leads} owner={form.data.default_owner_id} onAuto={(v) => form.setData('auto_create_leads', v)} onOwner={(v) => form.setData('default_owner_id', v)} error={form.errors.default_owner_id} />
                <Button type="submit" disabled={disabled || form.processing}>
                    {form.processing ? 'Checking with Meta…' : 'Connect'}
                </Button>
            </form>
        </Card>
    );
}

function Connected({ account, members, timeZone }: { account: Account; members: Option[]; timeZone?: string }) {
    const [auto, setAuto] = useState(account.auto_create_leads);
    const [owner, setOwner] = useState(account.default_owner_id === null ? '' : String(account.default_owner_id));
    const { errors } = usePage<SharedProps>().props;

    return (
        <Card title="Connected number">
            <dl className="grid grid-cols-1 gap-x-4 gap-y-2 text-sm sm:grid-cols-2">
                <div>
                    <dt className="text-slate-500">Number</dt>
                    <dd className="font-medium">
                        {account.display_phone_number ?? account.phone_number_id} {account.verified_name && `(${account.verified_name})`}
                    </dd>
                </div>
                <div>
                    <dt className="text-slate-500">Status</dt>
                    <dd className={account.status === 'active' ? 'text-green-800' : 'font-medium text-red-800'}>{account.status === 'active' ? 'Connected' : 'Reconnect required'}</dd>
                </div>
                <div>
                    <dt className="text-slate-500">Webhooks</dt>
                    <dd>{account.subscription_status === 'subscribed' ? 'Subscribed' : 'Not subscribed'}</dd>
                </div>
                <div>
                    <dt className="text-slate-500">Last message received</dt>
                    <dd>{formatDateTime(account.last_webhook_at, timeZone)}</dd>
                </div>
            </dl>
            {account.error && (
                <p role="alert" className="mt-3 rounded border border-red-200 bg-red-50 p-2 text-sm text-red-800">
                    {account.error}
                </p>
            )}
            {account.status !== 'active' && (
                <p className="mt-3 text-sm text-slate-700">To reconnect, disconnect below and connect again with a new token. Conversations and leads are kept.</p>
            )}

            <form
                className="mt-4 space-y-3 border-t border-slate-200 pt-4"
                onSubmit={(e) => {
                    e.preventDefault();
                    router.patch(`${BASE}/${account.id}`, { auto_create_leads: auto, default_owner_id: owner === '' ? null : Number(owner) }, keep);
                }}
            >
                <LeadSettings members={members} auto={auto} owner={owner} onAuto={setAuto} onOwner={setOwner} error={errors?.default_owner_id} />
                <Button type="submit" variant="secondary">
                    Save settings
                </Button>
            </form>

            <div className="mt-4 flex flex-wrap gap-2 border-t border-slate-200 pt-4">
                <Button variant="secondary" onClick={() => router.post(`${BASE}/${account.id}/validate`, {}, keep)}>
                    Check connection
                </Button>
                <Button
                    variant="danger"
                    onClick={() => {
                        if (window.confirm('Disconnect WhatsApp? The saved token is deleted and nobody can send or receive until it is connected again. Conversations and leads are kept.')) {
                            router.delete(`${BASE}/${account.id}`, keep);
                        }
                    }}
                >
                    Disconnect
                </Button>
            </div>
        </Card>
    );
}

function LeadSettings({ members, auto, owner, onAuto, onOwner, error }: { members: Option[]; auto: boolean; owner: string; onAuto: (v: boolean) => void; onOwner: (v: string) => void; error?: string }) {
    return (
        <fieldset className="space-y-3">
            <legend className="text-sm font-medium text-slate-700">New chats from unknown numbers</legend>
            <label className="flex items-center gap-2 text-sm">
                <input type="checkbox" checked={auto} onChange={(e) => onAuto(e.target.checked)} />
                Create a lead automatically (source: WhatsApp)
            </label>
            <Field label="Assign new WhatsApp leads to" id="default_owner_id" error={error}>
                <Select id="default_owner_id" value={owner} onChange={(e) => onOwner(e.target.value)} disabled={!auto} error={error}>
                    <option value="">Nobody (a manager assigns them)</option>
                    {members.map((m) => (
                        <option key={m.id} value={m.id}>
                            {m.name}
                        </option>
                    ))}
                </Select>
            </Field>
        </fieldset>
    );
}
