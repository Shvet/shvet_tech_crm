/** Formatting helpers. Timestamps arrive as UTC ISO strings; people read them in the workspace's timezone. */

export const DEFAULT_TZ = 'Asia/Kolkata';

export function formatDateTime(iso: string | null | undefined, timeZone: string = DEFAULT_TZ): string {
    if (!iso) return '—';
    return new Intl.DateTimeFormat('en-IN', { dateStyle: 'medium', timeStyle: 'short', timeZone }).format(new Date(iso));
}

export function formatDate(iso: string | null | undefined, timeZone: string = DEFAULT_TZ): string {
    if (!iso) return '—';
    return new Intl.DateTimeFormat('en-IN', { dateStyle: 'medium', timeZone }).format(new Date(iso));
}

export function formatMoney(value: string | number | null | undefined, currency = 'INR'): string {
    if (value === null || value === undefined || value === '') return '—';
    return new Intl.NumberFormat('en-IN', { style: 'currency', currency, maximumFractionDigits: 2 }).format(Number(value));
}

/** "2026-10-30T09:00:00Z" → "2026-10-30T14:30" as a datetime-local value in the given timezone. */
export function toLocalInput(iso: string, timeZone: string = DEFAULT_TZ): string {
    const parts = new Intl.DateTimeFormat('sv-SE', {
        timeZone,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        hourCycle: 'h23',
    }).format(new Date(iso));
    return parts.replace(' ', 'T');
}

/** Current time as a datetime-local value in the given timezone (default for new follow-ups). */
export function nowLocalInput(timeZone: string = DEFAULT_TZ, plusHours = 0): string {
    return toLocalInput(new Date(Date.now() + plusHours * 3600_000).toISOString(), timeZone);
}

export function titleCase(value: string): string {
    return value.replace(/_/g, ' ').replace(/^\w/, (c) => c.toUpperCase());
}
