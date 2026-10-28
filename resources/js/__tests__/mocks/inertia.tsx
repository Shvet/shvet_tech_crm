import { useRef, useState, type AnchorHTMLAttributes, type ReactNode } from 'react';
import { vi } from 'vitest';

/** Minimal stand-in for @inertiajs/react: records navigation and form submissions so tests can assert on them. */
export const router = { get: vi.fn(), post: vi.fn(), patch: vi.fn(), put: vi.fn(), delete: vi.fn() };

export const submissions: { method: string; url: string; data: Record<string, unknown> }[] = [];
export const formControl = { processing: false, errors: {} as Record<string, string> };

const defaultProps = {
    auth: { user: { name: 'Test User', email: 'test@example.test' } },
    tenant: { id: 't1', name: 'Shvet Technologies', timezone: 'Asia/Kolkata', currency: 'INR', role: 'Sales Manager', permissions: ['dashboard.view', 'leads.view_team', 'tasks.manage', 'users.view'] },
    flash: { status: null as string | null, duplicates: null as unknown },
    errors: {} as Record<string, string>,
};
let pageProps: Record<string, unknown> = { ...defaultProps };

export function setPageProps(props: Record<string, unknown> = {}) {
    pageProps = { ...defaultProps, ...props };
}

export function resetInertiaMock() {
    Object.values(router).forEach((fn) => fn.mockReset());
    submissions.length = 0;
    formControl.processing = false;
    formControl.errors = {};
    setPageProps();
}

export const usePage = () => ({ props: pageProps });
export const Head = () => null;

export function Link({ href, children, preserveScroll: _p, preserveState: _s, ...rest }: AnchorHTMLAttributes<HTMLAnchorElement> & { href: string; preserveScroll?: boolean; preserveState?: boolean; children?: ReactNode }) {
    return (
        <a href={href} {...rest}>
            {children}
        </a>
    );
}

export function useForm<T extends Record<string, unknown>>(initial: T) {
    const [data, setDataState] = useState<T>(initial);
    const transformer = useRef<((d: T) => Record<string, unknown>) | null>(null);

    const record = (method: string) => (url: string, options?: { onSuccess?: () => void }) => {
        submissions.push({ method, url, data: (transformer.current ? transformer.current(data) : data) as Record<string, unknown> });
        options?.onSuccess?.();
    };

    return {
        data,
        setData: (key: keyof T | Partial<T>, value?: unknown) =>
            setDataState((d) => (typeof key === 'object' ? { ...d, ...key } : { ...d, [key]: value })),
        errors: formControl.errors as Partial<Record<keyof T, string>>,
        processing: formControl.processing,
        post: record('post'),
        patch: record('patch'),
        put: record('put'),
        delete: record('delete'),
        transform: (fn: (d: T) => Record<string, unknown>) => {
            transformer.current = fn;
        },
        reset: (...keys: (keyof T)[]) => setDataState((d) => (keys.length ? { ...d, ...Object.fromEntries(keys.map((k) => [k, initial[k]])) } : initial)),
    };
}
