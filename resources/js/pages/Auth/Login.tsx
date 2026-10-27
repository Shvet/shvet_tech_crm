import { Head, Link, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Button, Field, GuestCard, StatusBanner, TextInput } from '../../components/ui';
import type { SharedProps } from '../../types';

export default function Login() {
    const { flash } = usePage<SharedProps>().props;
    const { data, setData, post, processing, errors, reset } = useForm({ email: '', password: '', remember: false });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        post('/login', { onFinish: () => reset('password') });
    };

    return (
        <GuestCard title="Sign in">
            <Head title="Sign in" />
            <StatusBanner message={flash?.status} />
            <form onSubmit={submit} className="space-y-4" noValidate>
                <Field id="email" label="E-mail" error={errors.email}>
                    <TextInput id="email" type="email" autoComplete="username" required value={data.email} error={errors.email} onChange={(e) => setData('email', e.target.value)} />
                </Field>
                <Field id="password" label="Password" error={errors.password}>
                    <TextInput id="password" type="password" autoComplete="current-password" required value={data.password} error={errors.password} onChange={(e) => setData('password', e.target.value)} />
                </Field>
                <label className="flex items-center gap-2 text-sm">
                    <input type="checkbox" checked={data.remember} onChange={(e) => setData('remember', e.target.checked)} /> Remember me
                </label>
                <Button type="submit" disabled={processing} aria-busy={processing}>
                    {processing ? 'Signing in…' : 'Sign in'}
                </Button>
            </form>
            <Link href="/forgot-password" className="text-sm text-indigo-700 hover:underline">
                Forgot your password?
            </Link>
        </GuestCard>
    );
}
