import { Head, usePage } from '@inertiajs/react';
import AppLayout from '../components/AppLayout';
import type { SharedProps } from '../types';

export default function Dashboard() {
    const { tenant } = usePage<SharedProps>().props;

    return (
        <AppLayout title="Dashboard">
            <Head title="Dashboard" />
            <p className="text-slate-700">
                You are working in <strong>{tenant?.name}</strong> as <strong>{tenant?.role}</strong>.
            </p>
            <p className="mt-2 text-sm text-slate-500">Lead metrics appear here once the lead module is added.</p>
        </AppLayout>
    );
}
