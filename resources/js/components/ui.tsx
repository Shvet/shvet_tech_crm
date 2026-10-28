import type { ButtonHTMLAttributes, InputHTMLAttributes, ReactNode, SelectHTMLAttributes, TextareaHTMLAttributes } from 'react';

export function Field({ label, error, id, children }: { label: string; error?: string; id: string; children: ReactNode }) {
    return (
        <div className="space-y-1">
            <label htmlFor={id} className="block text-sm font-medium text-slate-700">
                {label}
            </label>
            {children}
            {error && (
                <p id={`${id}-error`} role="alert" className="text-sm text-red-700">
                    {error}
                </p>
            )}
        </div>
    );
}

export function TextInput({ error, ...props }: InputHTMLAttributes<HTMLInputElement> & { error?: string; id: string }) {
    return (
        <input
            {...props}
            aria-invalid={error ? true : undefined}
            aria-describedby={error ? `${props.id}-error` : undefined}
            className="block w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-600 focus:outline-none focus:ring-1 focus:ring-indigo-600"
        />
    );
}

export function Button({ variant = 'primary', ...props }: ButtonHTMLAttributes<HTMLButtonElement> & { variant?: 'primary' | 'secondary' | 'danger' }) {
    const styles = {
        primary: 'bg-indigo-600 text-white hover:bg-indigo-700',
        secondary: 'border border-slate-300 bg-white text-slate-700 hover:bg-slate-50',
        danger: 'bg-red-600 text-white hover:bg-red-700',
    }[variant];
    return (
        <button
            {...props}
            className={`inline-flex items-center justify-center rounded-md px-3 py-2 text-sm font-medium disabled:cursor-not-allowed disabled:opacity-50 ${styles}`}
        />
    );
}

export function StatusBanner({ message }: { message?: string | null }) {
    if (!message) return null;
    return (
        <div role="status" className="rounded-md border border-green-200 bg-green-50 px-3 py-2 text-sm text-green-800">
            {message}
        </div>
    );
}

export function GuestCard({ title, children }: { title: string; children: ReactNode }) {
    return (
        <main className="flex min-h-screen items-center justify-center bg-slate-50 px-4">
            <div className="w-full max-w-sm space-y-4 rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                <h1 className="text-xl font-semibold text-slate-900">{title}</h1>
                {children}
            </div>
        </main>
    );
}

const FIELD_CLASS =
    'block w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-indigo-600 focus:outline-none focus:ring-1 focus:ring-indigo-600';

export function Select({ error, ...props }: SelectHTMLAttributes<HTMLSelectElement> & { error?: string }) {
    return <select {...props} aria-invalid={error ? true : undefined} aria-describedby={error && props.id ? `${props.id}-error` : undefined} className={FIELD_CLASS} />;
}

export function Textarea({ error, ...props }: TextareaHTMLAttributes<HTMLTextAreaElement> & { error?: string }) {
    return <textarea {...props} aria-invalid={error ? true : undefined} aria-describedby={error && props.id ? `${props.id}-error` : undefined} className={FIELD_CLASS} />;
}

export function ErrorBanner({ message }: { message?: string | null }) {
    if (!message) return null;
    return (
        <div role="alert" className="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800">
            {message}
        </div>
    );
}

export function Card({ title, children, actions }: { title?: string; children: ReactNode; actions?: ReactNode }) {
    return (
        <section className="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
            {(title || actions) && (
                <div className="mb-3 flex items-center justify-between gap-2">
                    {title && <h2 className="text-base font-semibold text-slate-900">{title}</h2>}
                    {actions}
                </div>
            )}
            {children}
        </section>
    );
}

export function EmptyState({ children }: { children: ReactNode }) {
    return <p className="rounded-md border border-dashed border-slate-300 px-4 py-6 text-center text-sm text-slate-500">{children}</p>;
}
