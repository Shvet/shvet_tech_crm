import { Head, router, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import AppLayout from '../../components/AppLayout';
import { Button, Field, TextInput } from '../../components/ui';
import { can } from '../../lib/permissions';
import type { Member, SharedProps } from '../../types';

export default function Index({ members, roles }: { members: Member[]; roles: string[] }) {
    const { tenant, errors: serverErrors } = usePage<SharedProps>().props;
    const canManage = can(tenant?.permissions, 'users.manage');
    const { data, setData, post, processing, errors, reset } = useForm({ name: '', email: '', role: roles[roles.length - 1] ?? '' });

    const invite = (e: FormEvent) => {
        e.preventDefault();
        post('/team/members', { onSuccess: () => reset('name', 'email') });
    };

    const remove = (m: Member) => {
        if (window.confirm(`Remove ${m.name} from this workspace?`)) {
            router.delete(`/team/members/${m.id}`);
        }
    };

    return (
        <AppLayout title="Team">
            <Head title="Team" />
            <div className="overflow-x-auto rounded-md border border-slate-200 bg-white">
                <table className="min-w-full text-left text-sm">
                    <thead className="bg-slate-50 text-slate-600">
                        <tr>
                            <th className="px-3 py-2">Name</th>
                            <th className="px-3 py-2">E-mail</th>
                            <th className="px-3 py-2">Role</th>
                            <th className="px-3 py-2">Status</th>
                            {canManage && <th className="px-3 py-2">Actions</th>}
                        </tr>
                    </thead>
                    <tbody>
                        {members.map((m) => (
                            <tr key={m.id} className="border-t border-slate-100">
                                <td className="px-3 py-2">{m.name}</td>
                                <td className="px-3 py-2">{m.email}</td>
                                <td className="px-3 py-2">
                                    {canManage && m.status !== 'removed' ? (
                                        <select aria-label={`Role for ${m.name}`} value={m.role ?? ''} onChange={(e) => router.patch(`/team/members/${m.id}`, { role: e.target.value })} className="rounded border border-slate-300 px-2 py-1">
                                            {roles.map((r) => (
                                                <option key={r}>{r}</option>
                                            ))}
                                        </select>
                                    ) : (
                                        m.role
                                    )}
                                </td>
                                <td className="px-3 py-2 capitalize">{m.status}</td>
                                {canManage && (
                                    <td className="space-x-2 px-3 py-2">
                                        {m.status === 'active' && <Button variant="secondary" onClick={() => router.patch(`/team/members/${m.id}`, { status: 'suspended' })}>Suspend</Button>}
                                        {m.status === 'suspended' && <Button variant="secondary" onClick={() => router.patch(`/team/members/${m.id}`, { status: 'active' })}>Reinstate</Button>}
                                        {m.status !== 'removed' && <Button variant="danger" onClick={() => remove(m)}>Remove</Button>}
                                    </td>
                                )}
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            {canManage && (
                <form onSubmit={invite} className="mt-6 max-w-md space-y-3" noValidate>
                    <h2 className="text-lg font-medium">Invite a team member</h2>
                    <Field id="name" label="Name" error={errors.name}>
                        <TextInput id="name" required value={data.name} error={errors.name} onChange={(e) => setData('name', e.target.value)} />
                    </Field>
                    <Field id="email" label="E-mail" error={errors.email}>
                        <TextInput id="email" type="email" required value={data.email} error={errors.email} onChange={(e) => setData('email', e.target.value)} />
                    </Field>
                    <Field id="role" label="Role" error={errors.role}>
                        <select id="role" value={data.role} onChange={(e) => setData('role', e.target.value)} className="block w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                            {roles.map((r) => (
                                <option key={r}>{r}</option>
                            ))}
                        </select>
                    </Field>
                    {serverErrors.member && <p role="alert" className="text-sm text-red-700">{serverErrors.member}</p>}
                    {serverErrors.status && <p role="alert" className="text-sm text-red-700">{serverErrors.status}</p>}
                    <Button type="submit" disabled={processing}>Send invitation</Button>
                </form>
            )}
        </AppLayout>
    );
}
