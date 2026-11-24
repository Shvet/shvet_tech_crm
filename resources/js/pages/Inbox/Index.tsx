import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState, type FormEvent } from 'react';
import AppLayout from '../../components/AppLayout';
import { Button, EmptyState, ErrorBanner, Select, Textarea, TextInput } from '../../components/ui';
import { formatDateTime } from '../../lib/format';
import { cleanQuery } from '../../lib/query';
import type { ConversationDetail, ConversationSummary, SharedProps, WhatsAppMessageRow } from '../../types';

interface Props {
    conversations: ConversationSummary[];
    filters: { q: string; filter: 'all' | 'unread' | 'unlinked' };
    account: { status: string; display_phone_number: string | null } | null;
    conversation: ConversationDetail | null;
    poll_seconds: number;
    can: { manage: boolean };
}

const STATUS_LABEL: Record<WhatsAppMessageRow['status'], string> = {
    queued: 'Sending…',
    sent: 'Sent',
    delivered: 'Delivered',
    read: 'Read',
    failed: 'Not sent',
    received: '',
};

export default function Index({ conversations, filters, account, conversation, poll_seconds, can }: Props) {
    const { tenant } = usePage<SharedProps>().props;
    const tz = tenant?.timezone;

    // Short polling while the tab is visible: new messages and delivery statuses appear without a reload.
    useEffect(() => {
        const id = window.setInterval(() => {
            if (document.visibilityState === 'visible') {
                router.reload({ only: ['conversations', 'conversation'] });
            }
        }, poll_seconds * 1000);
        return () => window.clearInterval(id);
    }, [poll_seconds]);

    return (
        <AppLayout title="WhatsApp inbox">
            <Head title="WhatsApp inbox" />
            {!account && (
                <div role="status" className="mb-4 rounded border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900">
                    WhatsApp is not connected for this workspace.{' '}
                    {can.manage ? (
                        <Link href="/integrations/whatsapp" className="font-medium underline">
                            Connect a number
                        </Link>
                    ) : (
                        'Ask an admin to connect a number.'
                    )}
                </div>
            )}
            {account && account.status !== 'active' && (
                <div role="alert" className="mb-4 rounded border border-red-300 bg-red-50 p-3 text-sm text-red-900">
                    WhatsApp needs to be reconnected by an admin. Messages cannot be sent until then.
                </div>
            )}
            <div className="grid gap-4 lg:grid-cols-[20rem_1fr]">
                <ConversationList conversations={conversations} filters={filters} activeId={conversation?.id ?? null} timeZone={tz} />
                {conversation ? (
                    <ConversationPane key={conversation.id} conversation={conversation} timeZone={tz} />
                ) : (
                    <EmptyState>Choose a conversation to read and reply.</EmptyState>
                )}
            </div>
        </AppLayout>
    );
}

function ConversationList({ conversations, filters, activeId, timeZone }: { conversations: ConversationSummary[]; filters: Props['filters']; activeId: string | null; timeZone?: string }) {
    const [q, setQ] = useState(filters.q);
    const base = activeId ? `/inbox/${activeId}` : '/inbox';
    const apply = (next: Partial<Props['filters']>) => router.get(base, cleanQuery({ ...filters, ...next, filter: (next.filter ?? filters.filter) === 'all' ? null : next.filter ?? filters.filter }), { preserveState: true, replace: true });

    return (
        <section aria-label="Conversations" className="min-w-0 space-y-3">
            <form
                role="search"
                onSubmit={(e) => {
                    e.preventDefault();
                    apply({ q });
                }}
                className="flex gap-2"
            >
                <label htmlFor="inbox-q" className="sr-only">
                    Search conversations
                </label>
                <TextInput id="inbox-q" value={q} onChange={(e) => setQ(e.target.value)} placeholder="Name or number" />
                <Button type="submit" variant="secondary">
                    Search
                </Button>
            </form>
            <div className="flex gap-2 text-sm" role="group" aria-label="Filter">
                {(['all', 'unread', 'unlinked'] as const).map((f) => (
                    <button
                        key={f}
                        type="button"
                        aria-pressed={filters.filter === f}
                        onClick={() => apply({ filter: f })}
                        className={`rounded-full border px-3 py-1 ${filters.filter === f ? 'border-indigo-600 bg-indigo-50 text-indigo-800' : 'border-slate-300 bg-white'}`}
                    >
                        {f === 'all' ? 'All' : f === 'unread' ? 'Unread' : 'Needs a lead'}
                    </button>
                ))}
            </div>
            {conversations.length === 0 ? (
                <EmptyState>No conversations yet.</EmptyState>
            ) : (
                <ul className="divide-y divide-slate-200 overflow-hidden rounded-lg border border-slate-200 bg-white">
                    {conversations.map((c) => (
                        <li key={c.id}>
                            <Link
                                href={`/inbox/${c.id}`}
                                preserveState
                                aria-current={c.id === activeId ? 'page' : undefined}
                                className={`block px-3 py-2 hover:bg-slate-50 ${c.id === activeId ? 'bg-indigo-50' : ''}`}
                            >
                                <span className="flex items-center justify-between gap-2">
                                    <span className="truncate font-medium">{c.name}</span>
                                    {c.unread > 0 && (
                                        <span className="rounded-full bg-green-600 px-2 text-xs font-semibold text-white">
                                            {c.unread}
                                            <span className="sr-only"> unread</span>
                                        </span>
                                    )}
                                </span>
                                <span className="flex justify-between gap-2 text-xs text-slate-500">
                                    <span className="truncate">{c.lead ? c.phone : c.link_status === 'ambiguous' ? 'Several leads match' : 'No lead yet'}</span>
                                    <span className="shrink-0">{formatDateTime(c.last_message_at, timeZone)}</span>
                                </span>
                            </Link>
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}

function ConversationPane({ conversation: c, timeZone }: { conversation: ConversationDetail; timeZone?: string }) {
    const bottom = useRef<HTMLDivElement>(null);
    useEffect(() => {
        bottom.current?.scrollIntoView?.({ block: 'end' });
    }, [c.messages.length]);

    return (
        <section aria-label={`Conversation with ${c.name}`} className="flex min-w-0 flex-col rounded-lg border border-slate-200 bg-white">
            <header className="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 px-4 py-3">
                <div className="min-w-0">
                    <h2 className="truncate text-lg font-semibold">{c.name}</h2>
                    <p className="text-sm text-slate-500">{c.phone}</p>
                </div>
                {c.lead ? (
                    <Link href={`/leads/${c.lead.id}`} className="text-sm font-medium text-indigo-700 hover:underline">
                        Open lead: {c.lead.name}
                    </Link>
                ) : (
                    <span className="text-sm text-slate-500">{c.link_status === 'ambiguous' ? 'Several leads share this number' : 'Not linked to a lead'}</span>
                )}
            </header>

            {c.can.link && !c.lead && <LinkLead conversation={c} />}

            <ol aria-label="Messages" className="max-h-[60vh] min-h-48 flex-1 space-y-2 overflow-y-auto px-4 py-3">
                {c.messages.map((m) => (
                    <li key={m.id} className={`flex ${m.direction === 'out' ? 'justify-end' : 'justify-start'}`}>
                        <div className={`max-w-[85%] rounded-lg px-3 py-2 text-sm ${m.direction === 'out' ? 'bg-green-50 ring-1 ring-green-200' : 'bg-slate-100'}`}>
                            {m.kind === 'template' && <p className="text-xs font-medium text-slate-500">Template: {m.template_name}</p>}
                            <p className="whitespace-pre-wrap break-words">{m.body ?? <em className="text-slate-500">[{m.kind} message]</em>}</p>
                            <p className="mt-1 text-xs text-slate-500">
                                {formatDateTime(m.at, timeZone)}
                                {m.sender && ` · ${m.sender}`}
                                {m.direction === 'out' && ` · ${STATUS_LABEL[m.status]}`}
                            </p>
                            {m.status === 'failed' && m.error && (
                                <p role="alert" className="mt-1 text-xs text-red-700">
                                    {m.error}
                                </p>
                            )}
                        </div>
                    </li>
                ))}
                <div ref={bottom} />
            </ol>

            <Composer conversation={c} timeZone={timeZone} />
        </section>
    );
}

function LinkLead({ conversation: c }: { conversation: ConversationDetail }) {
    const [leadId, setLeadId] = useState(c.link_candidates[0]?.id ?? '');
    const { errors } = usePage<SharedProps>().props;
    if (c.link_candidates.length === 0) {
        return <p className="border-b border-slate-200 px-4 py-2 text-sm text-slate-600">No lead in this workspace has this number. Create one from the Leads page, then link it here.</p>;
    }
    return (
        <form
            className="flex flex-wrap items-end gap-2 border-b border-slate-200 px-4 py-2"
            onSubmit={(e) => {
                e.preventDefault();
                router.post(`/inbox/${c.id}/link`, { lead_id: leadId }, { preserveScroll: true });
            }}
        >
            <div className="min-w-48 flex-1">
                <label htmlFor="link-lead" className="block text-sm font-medium text-slate-700">
                    Link to lead
                </label>
                <Select id="link-lead" value={leadId} onChange={(e) => setLeadId(e.target.value)} error={errors?.lead_id}>
                    {c.link_candidates.map((l) => (
                        <option key={l.id} value={l.id}>
                            {l.name}
                        </option>
                    ))}
                </Select>
            </div>
            <Button type="submit" variant="secondary">
                Link
            </Button>
            {errors?.lead_id && <ErrorBanner message={errors.lead_id} />}
        </form>
    );
}

export function Composer({ conversation: c, timeZone }: { conversation: ConversationDetail; timeZone?: string }) {
    const [mode, setMode] = useState<'text' | 'template'>(c.window_open ? 'text' : 'template');
    const form = useForm<{ type: 'text' | 'template'; body: string; template_id: string; params: string[] }>({ type: mode, body: '', template_id: '', params: [] });
    const template = c.templates.find((t) => String(t.id) === form.data.template_id);

    if (!c.can.reply) {
        return <p className="border-t border-slate-200 px-4 py-3 text-sm text-slate-600">You can read this conversation but not reply.</p>;
    }
    if (c.opted_out) {
        return (
            <p role="status" className="border-t border-slate-200 px-4 py-3 text-sm text-amber-900">
                This contact sent STOP and has opted out. Nothing can be sent until they write START.
            </p>
        );
    }
    if (!c.account_active) {
        return <p className="border-t border-slate-200 px-4 py-3 text-sm text-red-800">WhatsApp is not connected. An admin must reconnect it before you can reply.</p>;
    }

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform((d) => (mode === 'text' ? { type: 'text', body: d.body } : { type: 'template', template_id: Number(d.template_id), params: d.params }));
        form.post(`/inbox/${c.id}/messages`, {
            preserveScroll: true,
            onSuccess: () => form.reset('body', 'params'),
        });
    };

    return (
        <form onSubmit={submit} className="space-y-2 border-t border-slate-200 px-4 py-3" aria-label="Reply">
            <div className="flex flex-wrap items-center justify-between gap-2 text-xs text-slate-600">
                <span>
                    {c.window_open
                        ? `Free replies allowed until ${formatDateTime(c.window_closes_at, timeZone)}.`
                        : 'More than 24 hours since they last wrote: only an approved template can be sent.'}
                </span>
                {c.window_open && (
                    <button type="button" className="text-indigo-700 underline" onClick={() => setMode(mode === 'text' ? 'template' : 'text')}>
                        {mode === 'text' ? 'Use a template' : 'Write a message'}
                    </button>
                )}
            </div>

            {mode === 'text' ? (
                <>
                    <label htmlFor="wa-body" className="sr-only">
                        Message
                    </label>
                    <Textarea id="wa-body" rows={3} maxLength={4096} value={form.data.body} onChange={(e) => form.setData('body', e.target.value)} error={form.errors.body} />
                </>
            ) : c.templates.length === 0 ? (
                <p className="text-sm text-slate-600">No approved templates yet. An admin can refresh templates on the WhatsApp setup page.</p>
            ) : (
                <div className="space-y-2">
                    <label htmlFor="wa-template" className="block text-sm font-medium text-slate-700">
                        Template
                    </label>
                    <Select
                        id="wa-template"
                        value={form.data.template_id}
                        error={form.errors.template_id}
                        onChange={(e) => {
                            const t = c.templates.find((x) => String(x.id) === e.target.value);
                            form.setData({ template_id: e.target.value, params: Array.from({ length: t?.params ?? 0 }, () => '') });
                        }}
                    >
                        <option value="">Choose…</option>
                        {c.templates.map((t) => (
                            <option key={t.id} value={t.id}>
                                {t.name} ({t.language})
                            </option>
                        ))}
                    </Select>
                    {template?.body && <p className="rounded bg-slate-50 p-2 text-sm text-slate-700">{template.body}</p>}
                    {form.data.params.map((value, i) => (
                        <div key={i}>
                            <label htmlFor={`wa-param-${i}`} className="block text-sm text-slate-700">{`Value for {{${i + 1}}}`}</label>
                            <TextInput
                                id={`wa-param-${i}`}
                                value={value}
                                maxLength={500}
                                onChange={(e) => form.setData('params', form.data.params.map((p, j) => (j === i ? e.target.value : p)))}
                            />
                        </div>
                    ))}
                    {form.errors.params && <ErrorBanner message={form.errors.params} />}
                </div>
            )}
            <div className="flex justify-end">
                <Button type="submit" disabled={form.processing || (mode === 'text' ? form.data.body.trim() === '' : !template)}>
                    {form.processing ? 'Sending…' : 'Send'}
                </Button>
            </div>
        </form>
    );
}
