import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import AppLayout from '../../components/AppLayout';
import { Button, Field, TextInput } from '../../components/ui';

export default function Edit({ profile }: { profile: { name: string; email: string; phone: string | null } }) {
    const info = useForm({ name: profile.name, phone: profile.phone ?? '' });
    const pw = useForm({ current_password: '', password: '', password_confirmation: '' });

    const saveInfo = (e: FormEvent) => {
        e.preventDefault();
        info.patch('/profile');
    };
    const savePassword = (e: FormEvent) => {
        e.preventDefault();
        pw.put('/profile/password', { onSuccess: () => pw.reset() });
    };

    return (
        <AppLayout title="Profile">
            <Head title="Profile" />
            <form onSubmit={saveInfo} className="max-w-md space-y-3" noValidate>
                <p className="text-sm text-slate-600">{profile.email}</p>
                <Field id="name" label="Name" error={info.errors.name}>
                    <TextInput id="name" required value={info.data.name} error={info.errors.name} onChange={(e) => info.setData('name', e.target.value)} />
                </Field>
                <Field id="phone" label="Phone" error={info.errors.phone}>
                    <TextInput id="phone" value={info.data.phone} error={info.errors.phone} onChange={(e) => info.setData('phone', e.target.value)} />
                </Field>
                <Button type="submit" disabled={info.processing}>Save profile</Button>
            </form>

            <form onSubmit={savePassword} className="mt-8 max-w-md space-y-3" noValidate>
                <h2 className="text-lg font-medium">Change password</h2>
                <Field id="current_password" label="Current password" error={pw.errors.current_password}>
                    <TextInput id="current_password" type="password" autoComplete="current-password" value={pw.data.current_password} error={pw.errors.current_password} onChange={(e) => pw.setData('current_password', e.target.value)} />
                </Field>
                <Field id="password" label="New password" error={pw.errors.password}>
                    <TextInput id="password" type="password" autoComplete="new-password" value={pw.data.password} error={pw.errors.password} onChange={(e) => pw.setData('password', e.target.value)} />
                </Field>
                <Field id="password_confirmation" label="Confirm new password" error={pw.errors.password_confirmation}>
                    <TextInput id="password_confirmation" type="password" autoComplete="new-password" value={pw.data.password_confirmation} onChange={(e) => pw.setData('password_confirmation', e.target.value)} />
                </Field>
                <Button type="submit" disabled={pw.processing}>Update password</Button>
            </form>
        </AppLayout>
    );
}
