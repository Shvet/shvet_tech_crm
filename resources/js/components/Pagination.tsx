import { Link } from '@inertiajs/react';
import type { Paginated } from '../types';

/** Renders Laravel's paginator links. Labels contain HTML entities (&laquo;), so they are decoded, never injected. */
const decode = (label: string) => label.replace('&laquo;', '‹').replace('&raquo;', '›').replace(/&[a-z]+;/g, '');

export default function Pagination({ page }: { page: Pick<Paginated<unknown>, 'links' | 'last_page' | 'from' | 'to' | 'total'> }) {
    if (page.last_page <= 1) {
        return page.total > 0 ? <p className="text-sm text-slate-500">{page.total} result{page.total === 1 ? '' : 's'}</p> : null;
    }

    return (
        <nav aria-label="Pagination" className="flex flex-wrap items-center justify-between gap-2 text-sm">
            <p className="text-slate-500">
                Showing {page.from}–{page.to} of {page.total}
            </p>
            <ul className="flex flex-wrap gap-1">
                {page.links.map((link, i) => {
                    const label = decode(link.label);
                    const base = 'inline-block min-w-8 rounded border px-2 py-1 text-center';
                    if (!link.url) {
                        return (
                            <li key={i}>
                                <span aria-disabled="true" className={`${base} border-slate-200 text-slate-400`}>
                                    {label}
                                </span>
                            </li>
                        );
                    }
                    return (
                        <li key={i}>
                            <Link
                                href={link.url}
                                preserveScroll
                                aria-current={link.active ? 'page' : undefined}
                                className={`${base} ${link.active ? 'border-indigo-600 bg-indigo-600 text-white' : 'border-slate-300 hover:bg-slate-50'}`}
                            >
                                {label}
                            </Link>
                        </li>
                    );
                })}
            </ul>
        </nav>
    );
}
