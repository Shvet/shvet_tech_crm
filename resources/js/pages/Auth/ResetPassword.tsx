import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Button, Field, GuestCard, TextInput } from '../../components/ui';

export default function ResetPassword({ token, email }: { token: string; email: string }) {
    const { data, setData, post, processing, errors } = useForm({ token, email, password: '', password_confirmation: '' });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        post('/reset-password');
    };

    return (
        <GuestCard title="Choose a new password">
            <Head title="New password" />
            <form onSubmit={submit} className="space-y-4" noValidate>
                <Field id="email" label="E-mail" error={errors.email}>
                    <TextInput id="email" type="email" autoComplete="username" required value={data.email} error={errors.email} onChange={(e) => setData('email', e.target.value)} />
                </Field>
                <Field id="password" label="New password (min. 12 characters, mixed case, a number)" error={errors.password}>
                    <TextInput id="password" type="password" autoComplete="new-password" required value={data.password} error={errors.password} onChange={(e) => setData('password', e.target.value)} />
                </Field>
                <Field id="password_confirmation" label="Confirm password" error={errors.password_confirmation}>
                    <TextInput id="password_confirmation" type="password" autoComplete="new-password" required value={data.password_confirmation} onChange={(e) => setData('password_confirmation', e.target.value)} />
                </Field>
                <Button type="submit" disabled={processing}>
                    Save password
                </Button>
            </form>
        </GuestCard>
    );
}
