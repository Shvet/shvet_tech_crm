import { Head, Link, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Button, Field, GuestCard, StatusBanner, TextInput } from '../../components/ui';
import type { SharedProps } from '../../types';

export default function ForgotPassword() {
    const { flash } = usePage<SharedProps>().props;
    const { data, setData, post, processing, errors } = useForm({ email: '' });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        post('/forgot-password');
    };

    return (
        <GuestCard title="Reset your password">
            <Head title="Reset password" />
            <StatusBanner message={flash?.status} />
            <form onSubmit={submit} className="space-y-4" noValidate>
                <Field id="email" label="E-mail" error={errors.email}>
                    <TextInput id="email" type="email" autoComplete="username" required value={data.email} error={errors.email} onChange={(e) => setData('email', e.target.value)} />
                </Field>
                <Button type="submit" disabled={processing}>
                    Send reset link
                </Button>
            </form>
            <Link href="/login" className="text-sm text-indigo-700 hover:underline">
                Back to sign in
            </Link>
        </GuestCard>
    );
}
