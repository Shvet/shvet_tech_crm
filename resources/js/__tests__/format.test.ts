import { cleanQuery } from '../lib/query';
import { formatDate, formatDateTime, formatMoney, nowLocalInput, titleCase, toLocalInput } from '../lib/format';

describe('formatting helpers', () => {
    it('formats rupees with Indian digit grouping', () => {
        expect(formatMoney('125000.5', 'INR')).toMatch(/₹\s?1,25,000\.50/);
        expect(formatMoney(null)).toBe('—');
        expect(formatMoney('')).toBe('—');
    });

    it('renders UTC instants in the workspace timezone, not the browser timezone', () => {
        // 18:30 UTC on the 28th is already 00:00 on the 29th in Kolkata but still the 28th in Los Angeles.
        const iso = '2026-10-28T18:30:00Z';
        expect(formatDate(iso, 'Asia/Kolkata')).toContain('29');
        expect(formatDate(iso, 'America/Los_Angeles')).toContain('28');
        expect(formatDateTime(iso, 'Asia/Kolkata')).toMatch(/12:00\s?am/i);
    });

    it('converts a UTC instant to a datetime-local value in the given timezone', () => {
        expect(toLocalInput('2026-10-30T09:00:00Z', 'Asia/Kolkata')).toBe('2026-10-30T14:30');
        expect(toLocalInput('2026-10-30T09:00:00Z', 'UTC')).toBe('2026-10-30T09:00');
        expect(nowLocalInput('Asia/Kolkata', 24)).toMatch(/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/);
    });

    it('handles dashes, nulls and casing', () => {
        expect(formatDate(null)).toBe('—');
        expect(titleCase('proposal_sent')).toBe('Proposal sent');
    });

    it('cleanQuery drops empty values and keeps meaningful ones', () => {
        expect(cleanQuery({ q: 'priya', stage: '', owner: null, archived: false, page: 2, flag: true, none: undefined })).toEqual({ q: 'priya', page: '2', flag: '1' });
    });
});
