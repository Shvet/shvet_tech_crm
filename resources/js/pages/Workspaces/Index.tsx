import { Head, router, usePage } from '@inertiajs/react';
import AppLayout from '../../components/AppLayout';
import { Button } from '../../components/ui';
import type { SharedProps } from '../../types';

interface Workspace {
    id: string;
    name: string;
    invited: boolean;
}

export default function Index({ workspaces }: { workspaces: Workspace[] }) {
    const { errors } = usePage<SharedProps>().props;

    return (
        <AppLayout title="Choose a workspace">
            <Head title="Workspaces" />
            {errors.invitation && (
                <p role="alert" className="mb-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800">
                    {errors.invitation}
                </p>
            )}
            {workspaces.length === 0 ? (
                <p className="text-slate-600">You do not belong to any workspace yet. Ask an administrator to invite you.</p>
            ) : (
                <ul className="max-w-md divide-y divide-slate-200 rounded-md border border-slate-200 bg-white">
                    {workspaces.map((w) => (
                        <li key={w.id} className="flex items-center justify-between px-4 py-3">
                            <span>
                                {w.name} {w.invited && <span className="ml-2 rounded bg-amber-100 px-2 py-0.5 text-xs text-amber-800">Invitation</span>}
                            </span>
                            <Button onClick={() => router.post(`/workspaces/${w.id}/${w.invited ? 'accept' : 'select'}`)}>{w.invited ? 'Accept' : 'Open'}</Button>
                        </li>
                    ))}
                </ul>
            )}
        </AppLayout>
    );
}
