import { Link, router, usePage } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';
import { visibleNav } from '../lib/permissions';
import type { SharedProps } from '../types';
import { StatusBanner } from './ui';

export default function AppLayout({ title, children }: { title: string; children: ReactNode }) {
    const { auth, tenant, flash } = usePage<SharedProps>().props;
    const [open, setOpen] = useState(false);
    const nav = visibleNav(tenant?.permissions);

    return (
        <div className="min-h-screen bg-slate-50 text-slate-900 md:flex">
            <header className="flex items-center justify-between border-b border-slate-200 bg-white px-4 py-3 md:hidden">
                <span className="font-semibold">{tenant?.name ?? 'Shvet CRM'}</span>
                <button type="button" aria-expanded={open} aria-controls="sidebar" onClick={() => setOpen(!open)} className="rounded border px-2 py-1 text-sm">
                    Menu
                </button>
            </header>

            <aside id="sidebar" className={`${open ? 'block' : 'hidden'} w-full border-r border-slate-200 bg-white p-4 md:block md:w-60`}>
                <p className="hidden font-semibold md:block">{tenant?.name ?? 'Shvet CRM'}</p>
                {tenant?.role && <p className="text-xs text-slate-500">{tenant.role}</p>}
                <nav aria-label="Main" className="mt-4 space-y-1">
                    {nav.map((item) => (
                        <Link key={item.href} href={item.href} className="block rounded px-2 py-1.5 text-sm hover:bg-slate-100">
                            {item.label}
                        </Link>
                    ))}
                </nav>
                <div className="mt-6 space-y-1 border-t border-slate-200 pt-4 text-sm">
                    <p className="truncate text-slate-600">{auth?.user.name}</p>
                    <Link href="/workspaces" className="block text-indigo-700 hover:underline">
                        Switch workspace
                    </Link>
                    <Link href="/profile" className="block text-indigo-700 hover:underline">
                        Profile
                    </Link>
                    <button type="button" onClick={() => router.post('/logout')} className="text-indigo-700 hover:underline">
                        Sign out
                    </button>
                </div>
            </aside>

            <main className="flex-1 p-4 md:p-8">
                <h1 className="mb-4 text-2xl font-semibold">{title}</h1>
                <StatusBanner message={flash?.status} />
                <div className="mt-4">{children}</div>
            </main>
        </div>
    );
}
